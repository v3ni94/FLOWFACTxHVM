<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Flowfact\Client\CognitoTokenCache;
use App\Flowfact\Client\SettingsTokenProvider;
use App\Flowfact\Client\TokenScrubber;
use Illuminate\Support\Facades\Cache;

/**
 * Prüfbericht 2026-09-21 (Sicherheitsprüfung des Cognito-Tauschs, Befund
 * kritisch): TokenScrubber muss neben dem Zugangsschlüssel auch ein bereits
 * zwischengespeichertes Cognito-Token entfernen, ohne selbst einen Tausch
 * auszulösen oder eine Abhängigkeit zu CognitoTokenCache aufzubauen (sonst
 * Kreislauf über TransferLogRecorder).
 */
final class TokenScrubberTest extends FlowfactTestCase
{
    private function scrubber(): TokenScrubber
    {
        return app(TokenScrubber::class);
    }

    public function test_entfernt_ein_zwischengespeichertes_cognito_token(): void
    {
        $this->settings()->setSecret(SettingsTokenProvider::TOKEN_KEY, self::TOKEN);
        $cognitoToken = $this->vorgewaermtesCognitoToken(self::TOKEN);

        $bereinigt = $this->scrubber()->scrub('Fehler, Sitzung '.$cognitoToken.' abgelaufen');

        self::assertStringNotContainsString($cognitoToken, $bereinigt);
        self::assertStringContainsString(TokenScrubber::ERSATZ, $bereinigt);
    }

    public function test_entfernt_weiterhin_den_zugangsschluessel(): void
    {
        $this->settings()->setSecret(SettingsTokenProvider::TOKEN_KEY, self::TOKEN);

        $bereinigt = $this->scrubber()->scrub('Ungültiger Schlüssel '.self::TOKEN);

        self::assertStringNotContainsString(self::TOKEN, $bereinigt);
    }

    public function test_entfernt_beide_geheimnisse_in_einem_text(): void
    {
        $this->settings()->setSecret(SettingsTokenProvider::TOKEN_KEY, self::TOKEN);
        $cognitoToken = $this->vorgewaermtesCognitoToken(self::TOKEN);

        $bereinigt = $this->scrubber()->scrub($cognitoToken.' abgeleitet aus '.self::TOKEN);

        self::assertStringNotContainsString($cognitoToken, $bereinigt);
        self::assertStringNotContainsString(self::TOKEN, $bereinigt);
    }

    public function test_ohne_zwischengespeichertes_cognito_token_bleibt_text_unveraendert_bis_auf_den_schluessel(): void
    {
        $this->settings()->setSecret(SettingsTokenProvider::TOKEN_KEY, self::TOKEN);

        $bereinigt = $this->scrubber()->scrub('Nur der Schlüssel '.self::TOKEN.' hier');

        self::assertSame('Nur der Schlüssel '.TokenScrubber::ERSATZ.' hier', $bereinigt);
    }

    public function test_ohne_hinterlegten_zugangsschluessel_wird_nichts_veraendert(): void
    {
        self::assertSame('unveränderter Text', $this->scrubber()->scrub('unveränderter Text'));
    }

    /**
     * Ein Cognito-Token, das zu einem ANDEREN Zugangsschlüssel gehört (etwa
     * nach einem Schlüsseltausch), darf nicht mehr entfernt werden: der
     * Cache-Schlüssel ändert sich mit dem Zugangsschlüssel, ein alter Eintrag
     * ist für den aktuellen Schlüssel nicht mehr erreichbar.
     */
    public function test_cognito_token_eines_anderen_zugangsschluessels_wird_nicht_entfernt(): void
    {
        $this->settings()->setSecret(SettingsTokenProvider::TOKEN_KEY, 'NEUER-SCHLUESSEL');
        $altesCognitoToken = self::gueltigesCognitoToken();
        Cache::put(CognitoTokenCache::cacheKey('ALTER-SCHLUESSEL'), $altesCognitoToken, 1800);

        $bereinigt = $this->scrubber()->scrub('Token '.$altesCognitoToken.' noch da');

        self::assertStringContainsString($altesCognitoToken, $bereinigt);
    }
}
