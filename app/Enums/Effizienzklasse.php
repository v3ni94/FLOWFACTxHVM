<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Energieeffizienzklasse (Datenvertrag Abschnitt 2.4).
 */
enum Effizienzklasse: string
{
    case APlus = 'A+';
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case E = 'E';
    case F = 'F';
    case G = 'G';
    case H = 'H';

    public function label(): string
    {
        return match ($this) {
            self::APlus => 'A+',
            self::A => 'A',
            self::B => 'B',
            self::C => 'C',
            self::D => 'D',
            self::E => 'E',
            self::F => 'F',
            self::G => 'G',
            self::H => 'H',
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
