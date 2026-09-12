<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Medientyp (Datenvertrag Abschnitt 2.6, Masterprompt-Abgleich B.2).
 */
enum MediaTyp: string
{
    case Bild = 'bild';
    case Grundriss = 'grundriss';
    case Dokument = 'dokument';
    case Energieausweis = 'energieausweis';

    public function label(): string
    {
        return match ($this) {
            self::Bild => 'Bild',
            self::Grundriss => 'Grundriss',
            self::Dokument => 'Dokument',
            self::Energieausweis => 'Energieausweis',
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

    /**
     * Standardwert der Freigabe beim Anlegen (Masterprompt-Abgleich B.2):
     * Bilder und Grundrisse sind freigegeben, Dokumente und Energieausweise
     * bleiben intern, bis sie ausdrücklich freigegeben werden.
     */
    public function standardFreigegeben(): bool
    {
        return in_array($this, [self::Bild, self::Grundriss], true);
    }
}
