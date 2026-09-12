<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Anforderung eines Links zum Zurücksetzen des Passworts (Masterprompt
 * Abschnitt 6, Abgleich B.2). Die Ratenbegrenzung wirkt je E-Mail-Adresse,
 * höchstens drei Anforderungen pro Minute, unabhängig davon, ob zu dieser
 * Adresse ein Konto besteht.
 */
class ForgotPasswordRequest extends FormRequest
{
    public const int MAX_VERSUCHE = 3;

    public const int DECAY_SEKUNDEN = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
        ];
    }

    public function throttleKey(): string
    {
        return 'passwort-vergessen|'.Str::lower($this->string('email')->value());
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_VERSUCHE)) {
            return;
        }

        $sekunden = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', ['seconds' => $sekunden]),
        ]);
    }
}
