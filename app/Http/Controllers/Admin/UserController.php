<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()->orderBy('name')->get();

        return view('admin.users.index', [
            'users' => $users,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.create', [
            'roles' => UserRole::options(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $user = User::create([
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
            'role' => UserRole::from($request->string('role')->value()),
            'phone' => $request->string('phone')->value() ?: null,
            'password' => Hash::make($request->string('password')->value()),
            'is_active' => true,
        ]);

        return redirect()->route('admin.users.index')
            ->with('status', 'Der Benutzer "'.$user->name.'" wurde angelegt.');
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('admin.users.edit', [
            'editUser' => $user,
            'roles' => UserRole::options(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $neueRolle = UserRole::from($request->string('role')->value());

        if ($user->role === UserRole::Admin && $neueRolle !== UserRole::Admin) {
            if ($this->userPolicy()->isLastActiveAdmin($user)) {
                return back()->withInput()->withErrors([
                    'role' => 'Der letzte aktive Administrator kann nicht herabgestuft werden.',
                ]);
            }
        }

        $user->update([
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
            'role' => $neueRolle,
            'phone' => $request->string('phone')->value() ?: null,
        ]);

        return redirect()->route('admin.users.index')
            ->with('status', 'Der Benutzer "'.$user->name.'" wurde aktualisiert.');
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->is($user)) {
            return back()->with('error', 'Sie können sich nicht selbst deaktivieren.');
        }

        if ($this->userPolicy()->isLastActiveAdmin($user)) {
            return back()->with('error', 'Der letzte aktive Administrator kann nicht deaktiviert werden.');
        }

        $this->authorize('deactivate', $user);

        $user->is_active = false;
        // Prüfbericht 2026-09-11, Befund 11: Remember-Token zyklisieren, damit
        // ein zuvor gesetztes "Angemeldet bleiben"-Cookie des deaktivierten
        // Benutzers nicht mehr gültig ist.
        $user->setRememberToken(Str::random(60));
        $user->save();

        return back()->with('status', 'Der Benutzer "'.$user->name.'" wurde deaktiviert.');
    }

    public function activate(User $user): RedirectResponse
    {
        $this->authorize('activate', $user);

        $user->update(['is_active' => true]);

        return back()->with('status', 'Der Benutzer "'.$user->name.'" wurde aktiviert.');
    }

    public function resetTwoFactor(User $user): RedirectResponse
    {
        $this->authorize('resetTwoFactor', $user);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return back()->with('status', 'Der Zweitfaktor von "'.$user->name.'" wurde zurückgesetzt.');
    }

    private function userPolicy(): UserPolicy
    {
        return new UserPolicy;
    }
}
