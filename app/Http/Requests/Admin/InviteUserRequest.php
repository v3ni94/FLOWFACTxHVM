<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Einladung eines neuen Benutzers ohne Passwort (Masterprompt Abschnitt 6,
 * Abgleich B.2). Das Passwort setzt der eingeladene Benutzer selbst über den
 * Link in der Einladungsmail.
 */
class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(UserRole::class)],
            'darf_veroeffentlichen' => ['sometimes', 'boolean'],
        ];
    }
}
