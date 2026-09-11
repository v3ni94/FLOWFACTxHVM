<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ausstattungsqualität des Objekts (Datenvertrag Abschnitt 2.2).
 */
enum Ausstattungsqualitaet: string
{
    case Einfach = 'einfach';
    case Normal = 'normal';
    case Gehoben = 'gehoben';
    case Luxus = 'luxus';

    public function label(): string
    {
        return match ($this) {
            self::Einfach => 'Einfach',
            self::Normal => 'Normal',
            self::Gehoben => 'Gehoben',
            self::Luxus => 'Luxus',
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
