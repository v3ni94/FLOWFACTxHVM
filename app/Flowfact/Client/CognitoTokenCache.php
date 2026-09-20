<?php

declare(strict_types=1);

namespace App\Flowfact\Client;

use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Client\Exceptions\TransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;

/**
 * Tauscht den in den Einstellungen hinterlegten Zugangsschlüssel (FLOWFACT:
 * Einstellungen, Tools & Integration, API-Zugänge) gegen ein Cognito-Token,
 * das für rund 30 Minuten alle FLOWFACT-Dienste authentisiert
 * (flowfact-api.md Abschnitt 3.4, developers.flowfact.com/api, bestätigt
 * 21.09.2026). Der Zugangsschlüssel selbst ist kein Aufruftoken: ohne diesen
 * Tausch antwortet jeder Dienst mit HTTP 403 "Credentials invalid".
 *
 * Das Cognito-Token wird bis kurz vor Ablauf zwischengespeichert (Ablauf aus
 * dem exp-Anspruch des Tokens gelesen, sonst config('flowfact.cognito_ttl_
 * fallback_seconds')), Cache-Schlüssel je Zugangsschlüssel, damit ein
 * Austausch des Schlüssels sofort einen neuen Tausch auslöst. Ein doppelter
 * Tausch durch zwei gleichzeitige Anfragen ist harmlos (anders als die
 * FLOWFACT-Objektanlage, siehe SyncLease) und wird deshalb nicht gesperrt.
 *
 * Weder Zugangsschlüssel noch Cognito-Token gelangen in transfer_logs oder
 * Fehlermeldungen (ADR-008): der TransferLogRecorder entfernt den
 * Zugangsschlüssel automatisch über seinen TokenScrubber, das frisch
 * ausgestellte Cognito-Token ist dem Scrubber unbekannt und wird deshalb nie
 * als Rohtext protokolliert, nur als Platzhalter mit Gültigkeitsdauer.
 */
final class CognitoTokenCache
{
    private const string CACHE_PRAEFIX = 'flowfact.cognito_token.';

    private const string PFAD = '/admin-token-service/public/adminUser/authenticate';

    private const int SICHERHEITSABSTAND_SEKUNDEN = 60;

    private const int MINDESTGUELTIGKEIT_SEKUNDEN = 60;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly TransferLogRecorder $recorder,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 20,
        private readonly int $ttlFallbackSeconds = 1500,
    ) {}

    public static function cacheKey(string $zugangsschluessel): string
    {
        return self::CACHE_PRAEFIX.hash('sha256', $zugangsschluessel);
    }

    /**
     * @throws AuthenticationException wenn FLOWFACT den Zugangsschlüssel beim Tausch ablehnt
     * @throws TransportException bei Verbindungsfehlern oder Zeitüberschreitung
     */
    public function token(string $zugangsschluessel): string
    {
        $schluessel = self::cacheKey($zugangsschluessel);
        $vorhanden = Cache::get($schluessel);

        if (is_string($vorhanden) && $vorhanden !== '') {
            return $vorhanden;
        }

        [$cognitoToken, $gueltigkeitSekunden] = $this->tauschen($zugangsschluessel);

        Cache::put($schluessel, $cognitoToken, $gueltigkeitSekunden);

        return $cognitoToken;
    }

    /**
     * @return array{0: string, 1: int} Cognito-Token und verbleibende Gültigkeit in Sekunden
     */
    private function tauschen(string $zugangsschluessel): array
    {
        $url = rtrim($this->baseUrl, '/').self::PFAD;
        $aktion = 'admin-token-service GET /public/adminUser/authenticate';
        $start = hrtime(true);

        try {
            $response = $this->http
                ->withHeaders(['token' => $zugangsschluessel, 'Accept' => 'application/json'])
                ->timeout($this->timeoutSeconds)
                ->get($url);
        } catch (ConnectionException $exception) {
            $this->recorder->record($aktion, 'GET', $url, null, null, null, $this->dauer($start), false, 'Token-Tausch: Verbindungsfehler oder Zeitüberschreitung', null, null, $exception->getMessage());

            throw new TransportException('Token-Tausch bei FLOWFACT fehlgeschlagen: '.$exception->getMessage(), null, $exception);
        }

        $dauerMs = $this->dauer($start);

        if (! $response->successful()) {
            $this->recorder->record($aktion, 'GET', $url, null, $response->status(), mb_substr(trim($response->body()), 0, 500), $dauerMs, false, 'Token-Tausch fehlgeschlagen: HTTP '.$response->status());

            throw new AuthenticationException(
                'Der hinterlegte Zugangsschlüssel wurde beim Tausch gegen ein Sitzungstoken abgelehnt (admin-token-service, HTTP '.$response->status().'). Bitte den Schlüssel unter Einstellungen, Tools & Integration, API-Zugänge in FLOWFACT prüfen.',
                $response->status(),
            );
        }

        $cognitoToken = $this->extrahiere($response->body());

        if ($cognitoToken === null) {
            $this->recorder->record($aktion, 'GET', $url, null, $response->status(), '[Antwort ohne erkennbares Sitzungstoken]', $dauerMs, false, 'Token-Tausch: Antwort nicht als Sitzungstoken erkannt');

            throw new AuthenticationException('FLOWFACT hat beim Token-Tausch kein gültiges Sitzungstoken zurückgegeben.');
        }

        $gueltigkeitSekunden = $this->gueltigkeitSekunden($cognitoToken);

        $this->recorder->record($aktion, 'GET', $url, null, $response->status(), '[Sitzungstoken nicht protokolliert, gültig für ca. '.$gueltigkeitSekunden.' Sekunden]', $dauerMs, true, 'Token-Tausch erfolgreich');

        return [$cognitoToken, $gueltigkeitSekunden];
    }

    /**
     * Die Antwort ist entweder der rohe JWT-Text oder eine JSON-Zeichenkette
     * mit demselben Inhalt (developers.flowfact.com/api zeigt ein gekürztes
     * Beispiel ohne Content-Type-Angabe).
     */
    private function extrahiere(string $body): ?string
    {
        $text = trim($body);

        if ($text === '') {
            return null;
        }

        if (str_starts_with($text, '"') && str_ends_with($text, '"')) {
            $dekodiert = json_decode($text, true);

            if (is_string($dekodiert)) {
                $text = trim($dekodiert);
            }
        }

        return preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $text) === 1 ? $text : null;
    }

    private function gueltigkeitSekunden(string $jwt): int
    {
        $ablauf = $this->ablaufZeitstempel($jwt);

        if ($ablauf === null) {
            return $this->ttlFallbackSeconds;
        }

        $sekunden = $ablauf - time() - self::SICHERHEITSABSTAND_SEKUNDEN;

        return max(self::MINDESTGUELTIGKEIT_SEKUNDEN, $sekunden);
    }

    private function ablaufZeitstempel(string $jwt): ?int
    {
        $teile = explode('.', $jwt);

        if (count($teile) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($teile[1], '-_', '+/'), true);

        if ($payload === false) {
            return null;
        }

        $daten = json_decode($payload, true);

        return is_array($daten) && isset($daten['exp']) && is_numeric($daten['exp']) ? (int) $daten['exp'] : null;
    }

    private function dauer(int|float $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1_000_000);
    }
}
