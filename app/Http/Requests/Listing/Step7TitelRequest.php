<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Schritt 7: Überschrift und interne Bezeichnung (Masterprompt-Abgleich B.1
 * Schritt 7, Masterprompt Abschnitt 15). Die Berechtigung prüft bereits der
 * StepDispatcher (update), diese Anfrage prüft nur die Feldwerte.
 */
class Step7TitelRequest extends FormRequest
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
            'titel' => ['required', 'string', 'max:100'],
            'interne_bezeichnung' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'titel.required' => 'Bitte geben Sie eine Überschrift ein.',
            'titel.max' => 'Die Überschrift darf höchstens 100 Zeichen lang sein.',
        ];
    }
}
