<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Flowfact\Client\CognitoTokenCache;
use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Client\Exceptions\TransportException;
use App\Flowfact\Client\SettingsTokenProvider;
use App\Models\TransferLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Der Tausch des Zugangsschlüssels gegen ein Cognito-Token
 * (flowfact-api.md Abschnitt 3.4, developers.flowfact.com/api, bestätigt
 * 21.09.2026). Isolierte Tests des Tauschs selbst; das Zusammenspiel mit
 * FlowfactClient (Kopfzeile cognitoToken, Diagnose) prüfen FlowfactClientTest
 * und FlowfactSettingsTest.
 */
final class CognitoTokenCacheTest extends FlowfactTestCase
{
    private const string PFAD = '/admin-token-service/public/adminUser/authenticate';

    private function cache(): CognitoTokenCache
    {
        return app(CognitoTokenCache::class);
    }

    public function test_tauscht_den_zugangsschluessel_gegen_ein_cognito_token(): void
    {
        $cognitoToken = self::gueltigesCognitoToken();
        Http::fake([self::BASE.self::PFAD => Http::response($cognitoToken)]);

        $ergebnis = $this->cache()->token(self::TOKEN);

        self::assertSame($cognitoToken, $ergebnis);
        Http::assertSent(fn (Request $r): bool => $r->url() === self::BASE.self::PFAD
            && $r->hasHeader('token', self::TOKEN)
            && ! $r->hasHeader('cognitoToken'));
    }

    public function test_akzeptiert_die_antwort_auch_als_json_zeichenkette(): void
    {
        $cognitoToken = self::gueltigesCognitoToken();
        Http::fake([self::BASE.self::PFAD => Http::response('"'.$cognitoToken.'"')]);

        self::assertSame($cognitoToken, $this->cache()->token(self::TOKEN));
    }

    public function test_zweiter_aufruf_mit_gleichem_schluessel_nutzt_den_zwischenspeicher(): void
    {
        Http::fake([self::BASE.self::PFAD => Http::response(self::gueltigesCognitoToken())]);

        $erstes = $this->cache()->token(self::TOKEN);
        $zweites = $this->cache()->token(self::TOKEN);

        self::assertSame($erstes, $zweites);
        Http::assertSentCount(1);
    }

    public function test_ein_anderer_zugangsschluessel_loest_einen_neuen_tausch_aus(): void
    {
        // Zwei unterschiedliche Gültigkeitsdauern, damit sich die Tokens auch
        // dann unterscheiden, wenn beide Aufrufe innerhalb derselben Sekunde
        // erfolgen (der exp-Anspruch beruht auf time()).
        Http::fake([self::BASE.self::PFAD => Http::sequence()
            ->push(self::gueltigesCognitoToken(1800))
            ->push(self::gueltigesCognitoToken(3600))]);

        $erstes = $this->cache()->token(self::TOKEN);
        $zweites = $this->cache()->token('ANDERER-SCHLUESSEL');

        self::assertNotSame($erstes, $zweites);
        Http::assertSentCount(2);
    }

    /**
     * Ein bereits abgelaufener exp-Anspruch (oder ein sehr knapper, siehe
     * SICHERHEITSABSTAND_SEKUNDEN) unterschreitet nie die Mindestgültigkeit
     * von 60 Sekunden: ein zweiter Aufruf unmittelbar danach löst noch
     * keinen neuen Tausch aus, statt bei jedem Aufruf erneut zu tauschen.
     */
    public function test_bereits_abgelaufener_exp_anspruch_erhaelt_die_mindestgueltigkeit(): void
    {
        $abgelaufen = self::gueltigesCognitoToken(-3600);
        Http::fake([self::BASE.self::PFAD => Http::response($abgelaufen)]);

        $erstes = $this->cache()->token(self::TOKEN);
        $zweites = $this->cache()->token(self::TOKEN);

        self::assertSame($erstes, $zweites);
        Http::assertSentCount(1);
    }

    public function test_ohne_exp_anspruch_gilt_die_konfigurierte_rueckfallzeit(): void
    {
        config(['flowfact.cognito_ttl_fallback_seconds' => 42]);
        $ohneAblauf = 'kopf.'.base64_encode(json_encode(['sub' => 'ohne-exp'])).'.sig';
        Http::fake([self::BASE.self::PFAD => Http::response($ohneAblauf)]);

        $this->cache()->token(self::TOKEN);

        self::assertNotNull(Cache::get(CognitoTokenCache::cacheKey(self::TOKEN)));
    }

    public function test_lehnt_flowfact_den_schluessel_ab_wirft_authentication_exception_mit_klartext(): void
    {
        $this->settings()->setSecret(SettingsTokenProvider::TOKEN_KEY, self::TOKEN);
        Http::fake([self::BASE.self::PFAD => Http::response(['message' => 'Credentials invalid'], 403)]);

        try {
            $this->cache()->token(self::TOKEN);
            self::fail('Es wurde keine Ausnahme ausgelöst.');
        } catch (AuthenticationException $exception) {
            self::assertSame(403, $exception->httpStatus);
            self::assertStringContainsString('admin-token-service', $exception->getMessage());
            self::assertStringContainsString('API-Zugänge', $exception->getMessage());
        }

        $log = TransferLog::query()->latest('id')->first();
        self::assertFalse($log->erfolgreich);
        self::assertSame(403, $log->http_status);
        self::assertStringContainsString('Credentials invalid', $log->details['response']);
    }

    public function test_verbindungsfehler_wird_zur_transport_exception(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('cURL error 28: timeout'));

        $this->expectException(TransportException::class);
        $this->cache()->token(self::TOKEN);
    }

    public function test_antwort_ohne_erkennbares_token_wirft_authentication_exception(): void
    {
        Http::fake([self::BASE.self::PFAD => Http::response('nicht-einmal-drei-teile')]);

        $this->expectException(AuthenticationException::class);
        $this->cache()->token(self::TOKEN);
    }

    public function test_leere_antwort_wirft_authentication_exception(): void
    {
        Http::fake([self::BASE.self::PFAD => Http::response('')]);

        $this->expectException(AuthenticationException::class);
        $this->cache()->token(self::TOKEN);
    }

    /**
     * ADR-008: Weder Zugangsschlüssel noch das frisch ausgestellte
     * Cognito-Token dürfen im Übertragungsprotokoll landen, auch nicht bei
     * einem erfolgreichen Tausch.
     */
    public function test_erfolgreicher_tausch_protokolliert_weder_schluessel_noch_cognito_token(): void
    {
        $this->settings()->setSecret(SettingsTokenProvider::TOKEN_KEY, self::TOKEN);
        $cognitoToken = self::gueltigesCognitoToken();
        Http::fake([self::BASE.self::PFAD => Http::response($cognitoToken)]);

        $this->cache()->token(self::TOKEN);

        $log = TransferLog::query()->latest('id')->first();
        $json = json_encode($log->getAttributes(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(self::TOKEN, $json);
        self::assertStringNotContainsString($cognitoToken, $json);
        self::assertTrue($log->erfolgreich);
    }

    /**
     * FLOWFACT könnte den abgelehnten Schlüssel im Fehlerkörper spiegeln
     * (Worst Case, siehe TokenLeakTest): auch dann bleibt das Protokoll frei
     * vom Schlüssel, weil TransferLogRecorder ihn über TokenScrubber entfernt.
     */
    public function test_fehlerkoerper_mit_gespiegeltem_schluessel_wird_bereinigt(): void
    {
        $this->settings()->setSecret(SettingsTokenProvider::TOKEN_KEY, self::TOKEN);
        Http::fake([self::BASE.self::PFAD => Http::response(['message' => 'invalid '.self::TOKEN], 403)]);

        try {
            $this->cache()->token(self::TOKEN);
        } catch (AuthenticationException) {
        }

        $log = TransferLog::query()->latest('id')->first();
        $json = json_encode($log->getAttributes(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(self::TOKEN, $json);
        self::assertStringContainsString('[Token entfernt]', $json);
    }
}
