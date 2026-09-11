<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Listing;
use App\Models\User;

/**
 * Berechtigungen für Objekte (Datenvertrag Abschnitt 6).
 *
 * Anlegen, Bearbeiten, Veröffentlichen und Zurückziehen stehen jedem aktiven
 * Benutzer offen. Archivieren darf ein Admin immer, ein Mitarbeiter nur bei
 * eigenen Objekten. Löschen ist niemandem erlaubt, das Objekt bleibt aus
 * Nachvollziehbarkeit erhalten und wird stattdessen archiviert.
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
        return $user->is_active;
    }

    public function update(User $user, Listing $listing): bool
    {
        return $user->is_active;
    }

    public function publish(User $user, Listing $listing): bool
    {
        return $user->is_active;
    }

    public function withdraw(User $user, Listing $listing): bool
    {
        return $user->is_active;
    }

    public function archive(User $user, Listing $listing): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $listing->erstellt_von_user_id === $user->id;
    }

    public function delete(User $user, Listing $listing): bool
    {
        return false;
    }
}
