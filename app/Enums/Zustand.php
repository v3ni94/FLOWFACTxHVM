<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Zustand des Objekts (Datenvertrag Abschnitt 2.2).
 */
enum Zustand: string
{
    case Erstbezug = 'erstbezug';
    case Neuwertig = 'neuwertig';
    case Gepflegt = 'gepflegt';
    case Renovierungsbeduerftig = 'renovierungsbeduerftig';
    case Modernisiert = 'modernisiert';
    case Saniert = 'saniert';
    case Projektiert = 'projektiert';

    public function label(): string
    {
        return match ($this) {
            self::Erstbezug => 'Erstbezug',
            self::Neuwertig => 'Neuwertig',
            self::Gepflegt => 'Gepflegt',
            self::Renovierungsbeduerftig => 'Renovierungsbedürftig',
            self::Modernisiert => 'Modernisiert',
            self::Saniert => 'Saniert',
            self::Projektiert => 'Projektiert',
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
