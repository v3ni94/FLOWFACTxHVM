<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Textfeld eines Objekts (Datenvertrag Abschnitt 2.7).
 */
enum TextFeld: string
{
    case Titel = 'titel';
    case BeschreibungObjekt = 'beschreibung_objekt';
    case BeschreibungAusstattung = 'beschreibung_ausstattung';
    case BeschreibungLage = 'beschreibung_lage';
    case BeschreibungSonstiges = 'beschreibung_sonstiges';

    public function label(): string
    {
        return match ($this) {
            self::Titel => 'Titel',
            self::BeschreibungObjekt => 'Beschreibung Objekt',
            self::BeschreibungAusstattung => 'Beschreibung Ausstattung',
            self::BeschreibungLage => 'Beschreibung Lage',
            self::BeschreibungSonstiges => 'Beschreibung Sonstiges',
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
