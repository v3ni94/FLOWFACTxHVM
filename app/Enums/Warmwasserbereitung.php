<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Warmwasserbereitung (Masterprompt-Abgleich B.2).
 */
enum Warmwasserbereitung: string
{
    case Zentral = 'zentral';
    case Dezentral = 'dezentral';
    case Unbekannt = 'unbekannt';

    public function label(): string
    {
        return match ($this) {
            self::Zentral => 'Zentral',
            self::Dezentral => 'Dezentral',
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
}
