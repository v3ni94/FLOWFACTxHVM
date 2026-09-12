<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rollen der Benutzerverwaltung (Datenvertrag Abschnitt 2.1, Masterprompt
 * Abschnitt 6, Abgleich B.2/B.3).
 *
 * Admin verwaltet Benutzer, FLOWFACT-Zugang und Portale. Mitarbeiter erfasst
 * und veröffentlicht Objekte, verwaltet aber keine Benutzer und keine
 * Einstellungen. Leser darf Objekte ausschließlich lesend einsehen, ohne
 * Anlage-, Bearbeitungs- oder Veröffentlichungsrecht.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Mitarbeiter = 'mitarbeiter';
    case Leser = 'leser';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Mitarbeiter => 'Mitarbeiter',
            self::Leser => 'Lesender Benutzer',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::Admin->value => self::Admin->label(),
            self::Mitarbeiter->value => self::Mitarbeiter->label(),
            self::Leser->value => self::Leser->label(),
        ];
    }
}
