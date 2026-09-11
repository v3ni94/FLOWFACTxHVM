<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Domain\Security\RecoveryCodeGenerator;
use App\Domain\Security\TimeBasedOneTimePassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ConfirmPasswordRequest;
use App\Http\Requests\Account\TwoFactorConfirmRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(private readonly TimeBasedOneTimePassword $totp) {}

    /**
     * Startet die Einrichtung: erzeugt ein neues, unbestätigtes Geheimnis.
     * Ein erneuter Aufruf ersetzt ein zuvor unbestätigtes Geheimnis.
     */
    public function setup(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return back()->with('error', 'Der Zweitfaktor ist bereits aktiviert. Deaktivieren Sie ihn zuerst, um ein neues Gerät einzurichten.');
        }

        $user->forceFill([
            'two_factor_secret' => TimeBasedOneTimePassword::generateSecret(),
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return back()->with('status', 'Scannen Sie den Schlüssel mit Ihrer Authenticator-App und bestätigen Sie mit einem Code.');
    }

    /**
     * @throws ValidationException
     */
    public function confirm(TwoFactorConfirmRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->two_factor_secret === null || $user->hasTwoFactorEnabled()) {
            return back()->with('error', 'Es liegt keine offene Einrichtung vor. Starten Sie die Einrichtung erneut.');
        }

        if (! $this->totp->verify($user->two_factor_secret, $request->string('code')->value())) {
            throw ValidationException::withMessages([
                'code' => __('auth.two_factor_failed'),
            ]);
        }

        $klartextCodes = RecoveryCodeGenerator::generate();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(
                static fn (string $code): string => Hash::make($code),
                $klartextCodes
            ),
        ])->save();

        return back()
            ->with('status', 'Der Zweitfaktor ist jetzt aktiv. Bewahren Sie die Wiederherstellungscodes sicher auf, sie werden nur einmal angezeigt.')
            ->with('recovery_codes', $klartextCodes);
    }

    public function disable(ConfirmPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return back()->with('error', 'Der Zweitfaktor ist nicht aktiv.');
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return back()->with('status', 'Der Zweitfaktor wurde deaktiviert.');
    }

    public function regenerateRecoveryCodes(ConfirmPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return back()->with('error', 'Der Zweitfaktor ist nicht aktiv.');
        }

        $klartextCodes = RecoveryCodeGenerator::generate();

        $user->forceFill([
            'two_factor_recovery_codes' => array_map(
                static fn (string $code): string => Hash::make($code),
                $klartextCodes
            ),
        ])->save();

        return back()
            ->with('status', 'Neue Wiederherstellungscodes wurden erzeugt. Die alten Codes sind ungültig.')
            ->with('recovery_codes', $klartextCodes);
    }
}
