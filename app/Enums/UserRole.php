<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rollen der Benutzerverwaltung (Datenvertrag Abschnitt 2.1).
 *
 * Admin verwaltet Benutzer, FLOWFACT-Zugang und Portale. Mitarbeiter erfasst
 * und veröffentlicht Objekte, verwaltet aber keine Benutzer und keine
 * Einstellungen.
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Mitarbeiter = 'mitarbeiter';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Mitarbeiter => 'Mitarbeiter',
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
        ];
    }
}
