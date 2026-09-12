<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Abschluss der Passwort-Zurücksetzung (Masterprompt Abschnitt 6, Abgleich
 * B.2). Dieselbe Passwortrichtlinie wie bei der Änderung des eigenen
 * Passworts (Account\PasswordUpdateRequest).
 */
class ResetPasswordRequest extends FormRequest
{
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
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()],
        ];
    }
}
