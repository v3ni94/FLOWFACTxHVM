<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Portalveröffentlichungsstatus (Datenvertrag Abschnitt 4.3).
 */
enum PortalStatus: string
{
    case NichtVeroeffentlicht = 'nicht_veroeffentlicht';
    case Angefordert = 'angefordert';
    case Aktiv = 'aktiv';
    case Fehler = 'fehler';
    case Zurueckgezogen = 'zurueckgezogen';
    case Unbekannt = 'unbekannt';

    public function label(): string
    {
        return match ($this) {
            self::NichtVeroeffentlicht => 'Nicht veröffentlicht',
            self::Angefordert => 'Bestätigung ausstehend',
            self::Aktiv => 'Aktiv',
            self::Fehler => 'Fehler',
            self::Zurueckgezogen => 'Zurückgezogen',
            self::Unbekannt => 'Status nicht ermittelbar',
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
            self::Angefordert, self::Unbekannt => 'badge-warning',
            self::Aktiv => 'badge-success',
            self::Fehler => 'badge-error',
            self::NichtVeroeffentlicht, self::Zurueckgezogen => 'badge-neutral',
        };
    }
}
