<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Portalveröffentlichungsstatus (Datenvertrag Abschnitt 4.3, Masterprompt-Abgleich B.6).
 */
enum PortalStatus: string
{
    case NichtVeroeffentlicht = 'nicht_veroeffentlicht';
    case Angefordert = 'angefordert';
    case Aktiv = 'aktiv';
    case Fehler = 'fehler';
    case Zurueckgezogen = 'zurueckgezogen';
    case Unbekannt = 'unbekannt';

    /**
     * Fall B: Veröffentlichungsaufruf nicht autorisiert (HTTP 401 oder 403)
     * oder Portaltyp ohne Unterstützung. Kein Fehler, kein Erfolg: der
     * Abschluss erfolgt manuell in FLOWFACT.
     */
    case ManuelleFreigabeErforderlich = 'manuelle_freigabe_erforderlich';
    case DeaktivierungAngefordert = 'deaktivierung_angefordert';
    case DeaktivierungBestaetigt = 'deaktivierung_bestaetigt';

    public function label(): string
    {
        return match ($this) {
            self::NichtVeroeffentlicht => 'Nicht veröffentlicht',
            self::Angefordert => 'Bestätigung ausstehend',
            self::Aktiv => 'Aktiv',
            self::Fehler => 'Fehler',
            self::Zurueckgezogen => 'Zurückgezogen',
            self::Unbekannt => 'Status nicht ermittelbar',
            self::ManuelleFreigabeErforderlich => 'Manuelle Freigabe in FLOWFACT erforderlich',
            self::DeaktivierungAngefordert => 'Deaktivierung angefordert',
            self::DeaktivierungBestaetigt => 'Deaktivierung bestätigt',
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
            self::Angefordert, self::Unbekannt, self::ManuelleFreigabeErforderlich, self::DeaktivierungAngefordert => 'badge-warning',
            self::Aktiv => 'badge-success',
            self::Fehler => 'badge-error',
            self::NichtVeroeffentlicht, self::Zurueckgezogen, self::DeaktivierungBestaetigt => 'badge-neutral',
        };
    }

    /**
     * Ob auf diesem Portal noch eine Veröffentlichung offen ist
     * (angefordert oder aktiv).
     */
    public function istOffen(): bool
    {
        return in_array($this, [self::Angefordert, self::Aktiv], true);
    }
}
