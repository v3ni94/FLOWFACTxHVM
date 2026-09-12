<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InviteUserRequest;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()->orderBy('name')->get();

        $invitations = UserInvitation::query()
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->orderByDesc('created_at')
            ->get();

        return view('admin.users.index', [
            'users' => $users,
            'invitations' => $invitations,
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
            'darf_veroeffentlichen' => $request->boolean('darf_veroeffentlichen'),
        ]);

        return redirect()->route('admin.users.index')
            ->with('status', 'Der Benutzer "'.$user->name.'" wurde angelegt.');
    }

    /**
     * Einladungsformular (Masterprompt Abschnitt 6, Abgleich B.2). Dies ist
     * der in der Oberfläche vorgesehene Standardweg, einen neuen Benutzer
     * anzulegen; create()/store() mit einem Initialpasswort bleiben als
     * Ausweichmöglichkeit erhalten.
     */
    public function invite(): View
    {
        $this->authorize('create', User::class);

        return view('admin.users.invite', [
            'roles' => UserRole::options(),
        ]);
    }

    public function storeInvitation(InviteUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        [$invitation, $token] = UserInvitation::issue(
            $request->string('name')->value(),
            $request->string('email')->value(),
            UserRole::from($request->string('role')->value()),
            $request->boolean('darf_veroeffentlichen'),
            $request->user(),
        );

        Mail::to($invitation->email)->send(new UserInvitationMail($invitation, $token));

        return redirect()->route('admin.users.index')
            ->with('status', 'Die Einladung an "'.$invitation->email.'" wurde versendet.');
    }

    public function resendInvitation(UserInvitation $invitation): RedirectResponse
    {
        $this->authorize('create', User::class);

        abort_if($invitation->istVerwendet(), 409, 'Diese Einladung wurde bereits angenommen.');

        $token = $invitation->reissue();

        Mail::to($invitation->email)->send(new UserInvitationMail($invitation, $token));

        return back()->with('status', 'Die Einladung an "'.$invitation->email.'" wurde erneut versendet.');
    }

    public function revokeInvitation(UserInvitation $invitation): RedirectResponse
    {
        $this->authorize('create', User::class);

        $invitation->forceFill(['revoked_at' => now()])->save();

        return back()->with('status', 'Die Einladung an "'.$invitation->email.'" wurde widerrufen.');
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
            'darf_veroeffentlichen' => $request->boolean('darf_veroeffentlichen'),
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

        $this->beendeSitzungenVon($user);

        return back()->with('status', 'Der Benutzer "'.$user->name.'" wurde deaktiviert.');
    }

    /**
     * Löscht die Sitzungen des Benutzers aus der Tabelle "sessions"
     * (Masterprompt Abschnitt 6, Abgleich B.3: "Sperrung eines Benutzers
     * beendet bestehende Sitzungen"). Wirkt nur mit dem Datenbank-Sitzungs-
     * treiber, der in Produktion konfiguriert ist; die Prüfung mit
     * Schema::hasTable() schützt Umgebungen ohne diese Tabelle (z. B. den
     * Sitzungstreiber "array" in Tests, sofern die Tabelle dort nicht
     * angelegt wurde). EnsureUserIsActive meldet die noch laufende, aber nun
     * verwaiste Sitzung des Benutzers zusätzlich beim nächsten Seitenaufruf ab.
     */
    private function beendeSitzungenVon(User $user): void
    {
        if (! Schema::hasTable('sessions')) {
            return;
        }

        DB::table('sessions')->where('user_id', $user->id)->delete();
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
