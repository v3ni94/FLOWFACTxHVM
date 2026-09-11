<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Energieträger des Objekts (Datenvertrag Abschnitt 2.2).
 */
enum Energietraeger: string
{
    case Gas = 'gas';
    case Oel = 'oel';
    case Strom = 'strom';
    case Fernwaerme = 'fernwaerme';
    case Holz = 'holz';
    case Pellets = 'pellets';
    case Waermepumpe = 'waermepumpe';
    case Solar = 'solar';
    case Sonstiges = 'sonstiges';

    public function label(): string
    {
        return match ($this) {
            self::Gas => 'Gas',
            self::Oel => 'Öl',
            self::Strom => 'Strom',
            self::Fernwaerme => 'Fernwärme',
            self::Holz => 'Holz',
            self::Pellets => 'Pellets',
            self::Waermepumpe => 'Wärmepumpe',
            self::Solar => 'Solar',
            self::Sonstiges => 'Sonstiges',
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
