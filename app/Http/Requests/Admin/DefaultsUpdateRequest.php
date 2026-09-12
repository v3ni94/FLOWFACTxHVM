<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Vorgaben im Adminbereich (Masterprompt Abschnitt 18, Masterprompt-Abgleich
 * B.1 Schritt 7 und Abschnitt Review). Die Berechtigung prüft bereits die
 * Middleware role:admin der Route.
 */
class DefaultsUpdateRequest extends FormRequest
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
            'land' => ['required', 'string', 'size:2'],
            'ansprechpartner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'interne_bezeichnung_muster' => ['required', 'string', 'max:255'],
            'portale_miete' => ['sometimes', 'array'],
            'portale_miete.*' => ['string'],
            'portale_kauf' => ['sometimes', 'array'],
            'portale_kauf.*' => ['string'],
            'textbausteine_sonstiges' => ['nullable', 'string'],
        ];
    }
}
