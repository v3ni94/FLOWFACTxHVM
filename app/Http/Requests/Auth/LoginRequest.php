<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validiert die Zugangsdaten und kapselt die Ratenbegrenzung des Logins.
 *
 * Die Begrenzung wirkt je Kombination aus E-Mail-Adresse und IP-Adresse, damit
 * ein Angreifer nicht durch das Ausprobieren vieler E-Mail-Adressen von derselben
 * IP-Adresse aus die Begrenzung umgeht, und ein einzelnes Konto nicht von vielen
 * IP-Adressen aus gleichzeitig angegriffen werden kann, ohne dass ein einzelner
 * falscher Versuch eines anderen Benutzers am selben Standort ihn sperrt.
 */
class LoginRequest extends FormRequest
{
    public const int MAX_VERSUCHE = 5;

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
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    public function throttleKey(): string
    {
        return Str::lower($this->string('email')->value()).'|'.$this->ip();
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
