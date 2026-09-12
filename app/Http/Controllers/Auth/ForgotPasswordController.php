<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Anforderung des Links zum Zurücksetzen des Passworts (Masterprompt
 * Abschnitt 6, Abgleich B.2).
 *
 * Die Antwort verrät nie, ob zu einer E-Mail-Adresse ein Konto besteht: Es
 * wird immer dieselbe Meldung angezeigt, unabhängig vom tatsächlichen
 * Ergebnis des Password-Brokers. Nur die Ratenbegrenzung selbst (zu viele
 * Anfragen) wird als eigener Fehler gemeldet, weil sie für sich genommen
 * nichts über die Existenz eines Kontos aussagt.
 */
class ForgotPasswordController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * @throws ValidationException
     */
    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        $request->ensureIsNotRateLimited();
        RateLimiter::hit($request->throttleKey(), ForgotPasswordRequest::DECAY_SEKUNDEN);

        Password::broker('users')->sendResetLink([
            'email' => $request->string('email')->value(),
        ]);

        return back()->with(
            'status',
            'Falls zu dieser E-Mail-Adresse ein Konto besteht, wurde ein Link zum Zurücksetzen des Passworts versendet.'
        );
    }
}
