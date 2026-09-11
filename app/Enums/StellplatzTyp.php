<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stellplatztyp (Datenvertrag Abschnitt 2.2).
 */
enum StellplatzTyp: string
{
    case Garage = 'garage';
    case Tiefgarage = 'tiefgarage';
    case Aussenstellplatz = 'aussenstellplatz';
    case Carport = 'carport';
    case Duplex = 'duplex';
    case Keiner = 'keiner';

    public function label(): string
    {
        return match ($this) {
            self::Garage => 'Garage',
            self::Tiefgarage => 'Tiefgarage',
            self::Aussenstellplatz => 'Außenstellplatz',
            self::Carport => 'Carport',
            self::Duplex => 'Duplex',
            self::Keiner => 'Keiner',
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
