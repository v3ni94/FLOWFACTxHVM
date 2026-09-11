<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Bearbeitungsstatus eines Objekts (Datenvertrag Abschnitt 4.1).
 */
enum ListingStatus: string
{
    case Entwurf = 'entwurf';
    case Bereit = 'bereit';
    case Veroeffentlicht = 'veroeffentlicht';
    case Zurueckgezogen = 'zurueckgezogen';
    case Archiviert = 'archiviert';

    public function label(): string
    {
        return match ($this) {
            self::Entwurf => 'Entwurf',
            self::Bereit => 'Bereit',
            self::Veroeffentlicht => 'Veröffentlicht',
            self::Zurueckgezogen => 'Zurückgezogen',
            self::Archiviert => 'Archiviert',
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

    public function badgeClass(): string
    {
        return match ($this) {
            self::Entwurf => 'badge-neutral',
            self::Bereit => 'badge-info',
            self::Veroeffentlicht => 'badge-success',
            self::Zurueckgezogen => 'badge-warning',
            self::Archiviert => 'badge-neutral',
        };
    }
}
