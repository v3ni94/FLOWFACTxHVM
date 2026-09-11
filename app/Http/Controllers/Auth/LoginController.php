<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Session-Schlüssel, unter dem ein Benutzer nach korrektem Passwort, aber
     * vor bestätigtem Zweitfaktor, zwischengespeichert wird. Der Benutzer gilt
     * bis zum Abschluss der Prüfung ausdrücklich als NICHT angemeldet.
     */
    public const string SESSION_PENDING_USER_ID = 'auth.two_factor.user_id';

    public const string SESSION_PENDING_REMEMBER = 'auth.two_factor.remember';

    /**
     * Bcrypt-Hash eines zufälligen Werts, nur für den Zeitausgleich bei unbekannter E-Mail.
     */
    private const string DUMMY_HASH = '$2y$12$C6UzMDM.H6dfI/f/IKcEeO5tw7DPGpfCFyCwVfTZTxFqFBvGuZ3Ry';

    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * @throws ValidationException
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->ensureIsNotRateLimited();

        $user = User::where('email', $request->string('email')->value())->first();

        // Bewusst dieselbe Meldung für falsches Passwort und deaktivierten
        // Benutzer, damit kein Rückschluss auf die Existenz oder den Zustand
        // eines Kontos möglich ist (Datenvertrag, Sicherheitsmodell).
        // Auch bei unbekannter E-Mail wird ein Hash geprüft, damit die
        // Antwortzeit nicht verrät, ob ein Konto existiert.
        $hash = $user?->password ?? self::DUMMY_HASH;
        $passwordValid = Hash::check($request->string('password')->value(), $hash);

        if (! $user || ! $passwordValid || ! $user->is_active) {
            RateLimiter::hit($request->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($request->throttleKey());

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put(self::SESSION_PENDING_USER_ID, $user->id);
            $request->session()->put(self::SESSION_PENDING_REMEMBER, $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }

        $this->login($request, $user, $request->boolean('remember'));

        return redirect()->intended(route('app.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Meldet den Benutzer endgültig an: Sitzung rotieren, Zeitpunkt merken.
     */
    public static function login(Request $request, User $user, bool $remember): void
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();
    }
}
