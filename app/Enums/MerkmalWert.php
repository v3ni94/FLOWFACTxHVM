<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Dreiwertiges Ausstattungsmerkmal (Masterprompt-Abgleich B.2).
 *
 * Ältere Datensätze speichern boolesche Werte, neue Erfassungen die
 * Zeichenketten ja, nein und unbekannt. aus() liest beides. Ein unbekanntes
 * Merkmal wird weder als ja noch als nein übertragen (B.8).
 */
enum MerkmalWert: string
{
    case Ja = 'ja';
    case Nein = 'nein';
    case Unbekannt = 'unbekannt';

    public function label(): string
    {
        return match ($this) {
            self::Ja => 'Ja',
            self::Nein => 'Nein',
            self::Unbekannt => 'Unbekannt',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_combine(
            array_map(fn (self $fall): string => $fall->value, self::cases()),
            array_map(fn (self $fall): string => $fall->label(), self::cases()),
        );
    }

    /**
     * Liest einen gespeicherten Wert: true, 1 und "1" als ja, false, 0 und "0"
     * als nein, null, fehlende oder unbekannte Werte als unbekannt.
     */
    public static function aus(mixed $wert): self
    {
        if ($wert instanceof self) {
            return $wert;
        }

        if (is_bool($wert)) {
            return $wert ? self::Ja : self::Nein;
        }

        if (is_int($wert)) {
            return $wert === 1 ? self::Ja : ($wert === 0 ? self::Nein : self::Unbekannt);
        }

        if (is_string($wert)) {
            $normalisiert = strtolower(trim($wert));

            return match ($normalisiert) {
                'ja', 'true', '1' => self::Ja,
                'nein', 'false', '0' => self::Nein,
                default => self::tryFrom($normalisiert) ?? self::Unbekannt,
            };
        }

        return self::Unbekannt;
    }

    /**
     * Boolesche Sicht: ja = true, nein = false, unbekannt = null.
     */
    public function alsBool(): ?bool
    {
        return match ($this) {
            self::Ja => true,
            self::Nein => false,
            self::Unbekannt => null,
        };
    }
}
