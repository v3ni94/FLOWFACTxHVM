<?php

declare(strict_types=1);

namespace App\Flowfact\Client;

/**
 * Entfernt den API-Token aus beliebigen Texten und Strukturen, bevor sie in
 * Protokoll, Fehlermeldungen oder Oberfläche gelangen (ADR-008).
 *
 * WARUM: Der Token ist das einzige Geheimnis des Connectors. Er darf weder in
 * transfer_logs noch in Ausnahmemeldungen auftauchen, die die Oberfläche
 * anzeigt. Alle Ausgaben laufen deshalb durch diese Klasse.
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

        $token = $this->tokenProvider->token();

        if ($token === null || $token === '') {
            return $text;
        }

        return str_replace($token, self::ERSATZ, $text);
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
