<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Regeln der Benutzerverwaltung (Datenvertrag Abschnitt 6, Anforderung 5).
 *
 * Ein Admin kann sich selbst nicht deaktivieren, und der letzte aktive Admin
 * kann weder deaktiviert noch herabgestuft werden, damit die Anwendung nie in
 * einen Zustand ohne handlungsfähigen Administrator gerät.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function activate(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function deactivate(User $user, User $model): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        if ($user->is($model)) {
            return false;
        }

        return ! $this->isLastActiveAdmin($model);
    }

    /**
     * Ob $model auf die Rolle mitarbeiter herabgestuft werden darf.
     */
    public function demote(User $user, User $model): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        return ! $this->isLastActiveAdmin($model);
    }

    public function resetTwoFactor(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    /**
     * Ob $model der einzige noch aktive Administrator ist.
     */
    public function isLastActiveAdmin(User $model): bool
    {
        if ($model->role !== UserRole::Admin || ! $model->is_active) {
            return false;
        }

        return User::query()
            ->where('role', UserRole::Admin)
            ->where('is_active', true)
            ->count() <= 1;
    }
}
