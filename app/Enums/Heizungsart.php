<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Heizungsart des Objekts (Datenvertrag Abschnitt 2.2).
 */
enum Heizungsart: string
{
    case Zentralheizung = 'zentralheizung';
    case Etagenheizung = 'etagenheizung';
    case Fussbodenheizung = 'fussbodenheizung';
    case Fernwaerme = 'fernwaerme';
    case Ofenheizung = 'ofenheizung';
    case Waermepumpe = 'waermepumpe';

    public function label(): string
    {
        return match ($this) {
            self::Zentralheizung => 'Zentralheizung',
            self::Etagenheizung => 'Etagenheizung',
            self::Fussbodenheizung => 'Fußbodenheizung',
            self::Fernwaerme => 'Fernwärme',
            self::Ofenheizung => 'Ofenheizung',
            self::Waermepumpe => 'Wärmepumpe',
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
