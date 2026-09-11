<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Security\RecoveryCodeGenerator;
use App\Domain\Security\TimeBasedOneTimePassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    public const int MAX_VERSUCHE = 5;

    public function __construct(private readonly TimeBasedOneTimePassword $totp) {}

    public function create(): View|RedirectResponse
    {
        if (! session()->has(LoginController::SESSION_PENDING_USER_ID)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    /**
     * @throws ValidationException
     */
    public function store(TwoFactorChallengeRequest $request): RedirectResponse
    {
        $userId = $request->session()->get(LoginController::SESSION_PENDING_USER_ID);
        $user = $userId ? User::find($userId) : null;

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            $request->session()->forget([
                LoginController::SESSION_PENDING_USER_ID,
                LoginController::SESSION_PENDING_REMEMBER,
            ]);

            return redirect()->route('login');
        }

        $throttleKey = 'two-factor:'.$user->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_VERSUCHE)) {
            $sekunden = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'code' => __('auth.throttle', ['seconds' => $sekunden]),
            ]);
        }

        $erfolgreich = false;

        if ($request->filled('recovery_code')) {
            $erfolgreich = $this->verifyRecoveryCode($user, $request->string('recovery_code')->value());
        } elseif ($request->filled('code')) {
            $erfolgreich = $this->totp->verify($user->two_factor_secret, $request->string('code')->value());
        }

        if (! $erfolgreich) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'code' => __('auth.two_factor_failed'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        $remember = (bool) $request->session()->pull(LoginController::SESSION_PENDING_REMEMBER, false);
        $request->session()->forget(LoginController::SESSION_PENDING_USER_ID);

        LoginController::login($request, $user, $remember);

        return redirect()->intended(route('app.dashboard'));
    }

    /**
     * Prüft den Wiederherstellungscode gegen die gespeicherten Hashes und
     * verbraucht ihn bei Erfolg, damit er kein zweites Mal verwendet werden kann.
     */
    private function verifyRecoveryCode(User $user, string $eingabe): bool
    {
        $normalisiert = RecoveryCodeGenerator::normalize($eingabe);
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $index => $hash) {
            if (Hash::check($normalisiert, $hash)) {
                unset($codes[$index]);
                $user->forceFill([
                    'two_factor_recovery_codes' => array_values($codes),
                ])->save();

                return true;
            }
        }

        return false;
    }
}
