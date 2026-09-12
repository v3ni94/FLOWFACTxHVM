<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Objektart (Datenvertrag Abschnitt 2.2, Masterprompt-Abgleich B.2).
 */
enum Objektart: string
{
    case Wohnung = 'wohnung';
    case Haus = 'haus';
    case Mehrfamilienhaus = 'mehrfamilienhaus';
    case Gewerbe = 'gewerbe';
    case Stellplatz = 'stellplatz';
    case Grundstueck = 'grundstueck';

    /**
     * Feldgruppen, die je Objektart erfasst und geprüft werden
     * (Masterprompt-Abgleich B.1 Schritt 3: nur zur Objektart passende Felder).
     *
     * @var array<string, list<string>>
     */
    private const array FELDGRUPPEN = [
        'wohnflaeche' => ['wohnung', 'haus', 'mehrfamilienhaus'],
        'grundstueck' => ['haus', 'mehrfamilienhaus', 'grundstueck'],
        'nutzflaeche' => ['wohnung', 'haus', 'mehrfamilienhaus', 'gewerbe'],
        'gewerbeflaeche' => ['gewerbe'],
        'zimmer' => ['wohnung', 'haus'],
        'etage' => ['wohnung', 'gewerbe'],
        'wohnungsausstattung' => ['wohnung', 'haus', 'mehrfamilienhaus'],
        'energieausweis' => ['wohnung', 'haus', 'mehrfamilienhaus', 'gewerbe'],
    ];

    public function label(): string
    {
        return match ($this) {
            self::Wohnung => 'Wohnung',
            self::Haus => 'Haus',
            self::Mehrfamilienhaus => 'Mehrfamilienhaus',
            self::Gewerbe => 'Gewerbe',
            self::Stellplatz => 'Stellplatz',
            self::Grundstueck => 'Grundstück',
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
     * Ob die Feldgruppe für diese Objektart erfasst und geprüft wird.
     * Unbekannte Feldgruppen gelten als nicht benötigt.
     */
    public function benoetigt(string $feldgruppe): bool
    {
        return in_array($this->value, self::FELDGRUPPEN[$feldgruppe] ?? [], true);
    }

    /**
     * @return list<string>
     */
    public static function feldgruppen(): array
    {
        return array_keys(self::FELDGRUPPEN);
    }

    /**
     * Wohnobjekt im Sinne der Energieangaben (Masterprompt-Abgleich B.5):
     * Baujahr und Effizienzklasse sind nur bei Wohngebäuden Pflicht.
     */
    public function istWohnobjekt(): bool
    {
        return in_array($this, [self::Wohnung, self::Haus, self::Mehrfamilienhaus], true);
    }
}
