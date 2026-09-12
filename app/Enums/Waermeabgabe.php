<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wärmeabgabe der Heizung (Masterprompt-Abgleich B.2).
 */
enum Waermeabgabe: string
{
    case Heizkoerper = 'heizkoerper';
    case Fussbodenheizung = 'fussbodenheizung';
    case Beides = 'beides';
    case Unbekannt = 'unbekannt';

    public function label(): string
    {
        return match ($this) {
            self::Heizkoerper => 'Heizkörper',
            self::Fussbodenheizung => 'Fußbodenheizung',
            self::Beides => 'Heizkörper und Fußbodenheizung',
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
