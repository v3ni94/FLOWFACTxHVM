<?php

declare(strict_types=1);

namespace App\Flowfact\Client;

use Illuminate\Support\Facades\Cache;

/**
 * Entfernt den Zugangsschlüssel UND ein bereits ausgestelltes Cognito-Token
 * aus beliebigen Texten und Strukturen, bevor sie in Protokoll,
 * Fehlermeldungen oder Oberfläche gelangen (ADR-008).
 *
 * WARUM: Beide sind Geheimnisse des Connectors. Sie dürfen weder in
 * transfer_logs noch in Ausnahmemeldungen auftauchen, die die Oberfläche
 * anzeigt (unter anderem `letzter_fehler` auf der Objektseite, für jeden
 * aktiven Benutzer inklusive der Rolle Leser sichtbar). Alle Ausgaben laufen
 * deshalb durch diese Klasse.
 *
 * Das Cognito-Token wird über CognitoTokenCache::cacheKey() nur GELESEN
 * (Cache::get, kein Tausch), damit keine Abhängigkeit zu CognitoTokenCache
 * selbst entsteht: dessen eigener Tauschvorgang protokolliert über
 * TransferLogRecorder, der wiederum diese Klasse verwendet. Eine
 * Konstruktor-Abhängigkeit hierher auf CognitoTokenCache würde einen
 * Kreislauf erzeugen.
 */
final class TokenScrubber
{
    public const string ERSATZ = '[Token entfernt]';

    public function __construct(
        private readonly TokenProvider $tokenProvider,
    ) {}

    public function scrub(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = $this->ersetzen($text, $this->tokenProvider->token());
        $text = $this->ersetzen($text, $this->zwischengespeichertesCognitoToken());

        return $text;
    }

    private function ersetzen(string $text, ?string $geheimnis): string
    {
        if ($geheimnis === null || $geheimnis === '') {
            return $text;
        }

        return str_replace($geheimnis, self::ERSATZ, $text);
    }

    private function zwischengespeichertesCognitoToken(): ?string
    {
        $zugangsschluessel = $this->tokenProvider->token();

        if ($zugangsschluessel === null || $zugangsschluessel === '') {
            return null;
        }

        $wert = Cache::get(CognitoTokenCache::cacheKey($zugangsschluessel));

        return is_string($wert) && $wert !== '' ? $wert : null;
    }

    /**
     * @param  array<mixed>  $daten
     * @return array<mixed>
     */
    public function scrubArray(array $daten): array
    {
        $ergebnis = [];

        foreach ($daten as $schluessel => $wert) {
            $neuerSchluessel = is_string($schluessel) ? $this->scrub($schluessel) : $schluessel;

            if (is_array($wert)) {
                $ergebnis[$neuerSchluessel] = $this->scrubArray($wert);
            } elseif (is_string($wert)) {
                $ergebnis[$neuerSchluessel] = $this->scrub($wert);
            } else {
                $ergebnis[$neuerSchluessel] = $wert;
            }
        }

        return $ergebnis;
    }
}
