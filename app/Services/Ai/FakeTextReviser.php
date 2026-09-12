<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Listing;

/**
 * Regelbasierte Überarbeitung ohne externen Aufruf. Aktiv in Tests und ohne
 * konfigurierten KI-Anbieter. Verändert keine Inhalte, nur Form.
 */
final class FakeTextReviser implements TextReviser
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function modell(): string
    {
        return 'vorlage';
    }

    public function revise(Listing $listing, string $text, string $anweisung): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return match ($anweisung) {
            self::KUERZER => $this->kuerzer($text),
            self::SACHLICHER => $this->sachlicher($text),
            self::SPRACHLICH => $this->sprachlich($text),
            default => throw new TextGenerationException('Unbekannte Überarbeitungsanweisung.'),
        };
    }

    private function kuerzer(string $text): string
    {
        $saetze = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];
        $behalten = max(1, (int) ceil(count($saetze) * 0.6));

        return implode(' ', array_slice($saetze, 0, $behalten));
    }

    private function sachlicher(string $text): string
    {
        $ersetzungen = [
            '/\b(traumhaft|wunderschön|einzigartig|perfekt|absolut|unglaublich|fantastisch|hervorragend)e?[rsnm]?\b\s*/iu' => '',
            '/!+/u' => '.',
        ];

        return trim(preg_replace(array_keys($ersetzungen), array_values($ersetzungen), $text) ?? $text);
    }

    private function sprachlich(string $text): string
    {
        $text = preg_replace('/\s+([.,;:!?])/u', '$1', $text) ?? $text;
        $text = preg_replace('/\s{2,}/u', ' ', $text) ?? $text;

        return preg_replace_callback('/(^|[.!?]\s+)(\p{Ll})/u', static fn (array $m): string => $m[1].mb_strtoupper($m[2]), $text) ?? $text;
    }
}
