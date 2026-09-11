<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Vermarktungsart eines Objekts (Datenvertrag Abschnitt 2.2).
 */
enum Vermarktungsart: string
{
    case Miete = 'miete';
    case Kauf = 'kauf';

    public function label(): string
    {
        return match ($this) {
            self::Miete => 'Miete',
            self::Kauf => 'Kauf',
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
