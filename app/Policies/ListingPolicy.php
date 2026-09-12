<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Listing;
use App\Models\User;

/**
 * Berechtigungen für Objekte (Masterprompt Abschnitt 6, Abgleich B.3;
 * ersetzt die frühere, undifferenzierte Fassung aus Datenvertrag Abschnitt 6).
 *
 * Sehen steht jedem aktiven Benutzer offen, auch der Rolle Leser. Anlegen,
 * Bearbeiten und Duplizieren stehen Admin und Mitarbeiter offen, nie Leser.
 * Veröffentlichen und Zurückziehen setzen zusätzlich das Bearbeitungsrecht
 * und User::kannVeroeffentlichen() voraus (Admin immer, Mitarbeiter nur mit
 * dem Flag darf_veroeffentlichen). Archivieren darf ein Admin immer, ein
 * Mitarbeiter nur bei eigenen Objekten. Löschen ist niemandem erlaubt, das
 * Objekt bleibt aus Nachvollziehbarkeit erhalten und wird stattdessen
 * archiviert.
 *
 * ÜBERGANGSREGEL (bewusste Abweichung von der wörtlichen Tabelle in B.3,
 * Rückwärtskompatibilität dieser Welle): Der aktuelle, in dieser Welle nicht
 * angepasste Assistent (app/Http/Controllers/App/**) setzt bearbeiter_user_id
 * nie. Ist das Feld leer, bleibt ein Objekt für jeden aktiven Mitarbeiter
 * bearbeitbar wie bisher, statt am fehlenden Bearbeiter zu scheitern. Sobald
 * der neue Assistent (Welle 2) bearbeiter_user_id setzt, greift die
 * Einschränkung nach B.3 uneingeschränkt.
 */
class ListingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Listing $listing): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active && ! $user->isLeser();
    }

    public function update(User $user, Listing $listing): bool
    {
        if (! $user->is_active || $user->isLeser()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($listing->bearbeiter_user_id === null) {
            return true;
        }

        return $listing->bearbeiter_user_id === $user->id
            || $listing->erstellt_von_user_id === $user->id
            || (bool) $listing->freigegeben_fuer_alle;
    }

    public function publish(User $user, Listing $listing): bool
    {
        return $this->update($user, $listing) && $user->kannVeroeffentlichen();
    }

    public function withdraw(User $user, Listing $listing): bool
    {
        return $this->update($user, $listing) && $user->kannVeroeffentlichen();
    }

    public function archive(User $user, Listing $listing): bool
    {
        if (! $user->is_active || $user->isLeser()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $listing->erstellt_von_user_id === $user->id;
    }

    public function duplicate(User $user): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Listing $listing): bool
    {
        return false;
    }
}
