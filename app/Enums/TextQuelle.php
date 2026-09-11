<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Herkunft eines Textvorschlags (Datenvertrag Abschnitt 2.7).
 */
enum TextQuelle: string
{
    case Ki = 'ki';
    case Manuell = 'manuell';

    public function label(): string
    {
        return match ($this) {
            self::Ki => 'KI',
            self::Manuell => 'Manuell',
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
