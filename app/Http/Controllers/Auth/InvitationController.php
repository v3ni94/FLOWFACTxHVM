<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * Annahme einer Benutzereinladung (Masterprompt Abschnitt 6, Abgleich B.2).
 *
 * Der Benutzer existiert erst ab hier: Name, E-Mail-Adresse, Rolle und das
 * Recht darf_veroeffentlichen stammen aus der Einladung, nur das Passwort
 * wird hier von der eingeladenen Person selbst vergeben. Ein bereits
 * verwendeter, widerrufener oder abgelaufener Token führt zu einer klaren
 * deutschen Meldung und meldet niemanden an.
 */
class InvitationController extends Controller
{
    public function show(string $token): View
    {
        $invitation = UserInvitation::findByToken($token);

        return view('auth.invitation-accept', [
            'token' => $token,
            'invitation' => $invitation,
            'fehlermeldung' => $this->fehlermeldung($invitation),
        ]);
    }

    public function store(AcceptInvitationRequest $request, string $token): RedirectResponse
    {
        $invitation = UserInvitation::findByToken($token);
        $fehlermeldung = $this->fehlermeldung($invitation);

        if ($fehlermeldung !== null || $invitation === null) {
            return redirect()->route('invitation.show', ['token' => $token])
                ->with('error', $fehlermeldung ?? __('auth.invitation_invalid'));
        }

        $user = User::create([
            'name' => $invitation->name,
            'email' => $invitation->email,
            'role' => $invitation->role,
            'darf_veroeffentlichen' => $invitation->darf_veroeffentlichen,
            'password' => Hash::make($request->string('password')->value()),
            'is_active' => true,
        ]);

        $invitation->forceFill(['accepted_at' => now()])->save();

        LoginController::login($request, $user, false);

        return redirect()->route('app.dashboard')
            ->with('status', 'Willkommen bei Müller FLOW. Ihr Konto wurde eingerichtet.');
    }

    private function fehlermeldung(?UserInvitation $invitation): ?string
    {
        return match (true) {
            $invitation === null => __('auth.invitation_invalid'),
            $invitation->istWiderrufen() => __('auth.invitation_revoked'),
            $invitation->istVerwendet() => __('auth.invitation_used'),
            $invitation->istAbgelaufen() => __('auth.invitation_expired'),
            default => null,
        };
    }
}
