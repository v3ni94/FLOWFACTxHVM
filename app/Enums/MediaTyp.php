<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Medientyp (Datenvertrag Abschnitt 2.6).
 */
enum MediaTyp: string
{
    case Bild = 'bild';
    case Grundriss = 'grundriss';
    case Dokument = 'dokument';

    public function label(): string
    {
        return match ($this) {
            self::Bild => 'Bild',
            self::Grundriss => 'Grundriss',
            self::Dokument => 'Dokument',
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
