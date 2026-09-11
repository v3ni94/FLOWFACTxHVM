<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Verfügbarkeit des Objekts (Datenvertrag Abschnitt 2.2).
 */
enum VerfuegbarAbTyp: string
{
    case Sofort = 'sofort';
    case NachVereinbarung = 'nach_vereinbarung';
    case Datum = 'datum';

    public function label(): string
    {
        return match ($this) {
            self::Sofort => 'Sofort',
            self::NachVereinbarung => 'Nach Vereinbarung',
            self::Datum => 'Ab Datum',
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
