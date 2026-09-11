<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Typ des Energieausweises (Datenvertrag Abschnitt 2.4).
 */
enum Ausweistyp: string
{
    case Bedarf = 'bedarf';
    case Verbrauch = 'verbrauch';

    public function label(): string
    {
        return match ($this) {
            self::Bedarf => 'Bedarfsausweis',
            self::Verbrauch => 'Verbrauchsausweis',
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
