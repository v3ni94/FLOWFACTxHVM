<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Objektart (Datenvertrag Abschnitt 2.2).
 */
enum Objektart: string
{
    case Wohnung = 'wohnung';
    case Haus = 'haus';
    case Gewerbe = 'gewerbe';
    case Stellplatz = 'stellplatz';
    case Grundstueck = 'grundstueck';

    public function label(): string
    {
        return match ($this) {
            self::Wohnung => 'Wohnung',
            self::Haus => 'Haus',
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
}
