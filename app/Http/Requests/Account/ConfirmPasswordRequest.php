<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Für sicherheitsrelevante Aktionen im eigenen Konto (2FA deaktivieren,
 * Wiederherstellungscodes neu erzeugen), die das aktuelle Passwort verlangen.
 */
class ConfirmPasswordRequest extends FormRequest
{
    /**
     * Zwei Formulare auf der Kontoseite (deaktivieren, Codes neu erzeugen)
     * verwenden beide ein Feld "current_password". Ein eigener Error-Bag je
     * Route verhindert, dass ein Fehler im falschen Formular erscheint.
     */
    public function authorize(): bool
    {
        $this->errorBag = match ($this->route()?->getName()) {
            'account.two-factor.disable' => 'disable',
            'account.two-factor.recovery' => 'recovery',
            default => 'default',
        };

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
        ];
    }
}
