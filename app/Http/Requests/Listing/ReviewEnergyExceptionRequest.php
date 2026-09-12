<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bestätigung der Ausnahme von der Energieausweispflicht durch einen
 * Administrator auf der Prüfseite (Masterprompt-Abgleich B.5). Nur ein Admin
 * darf diese Aktion aufrufen (in ReviewController::confirmEnergyException
 * über role:admin abgesichert).
 */
class ReviewEnergyExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ausnahme_begruendung' => ['required', 'string', 'min:5'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ausnahme_begruendung.required' => 'Bitte begründen Sie die Ausnahme (z. B. Baudenkmal, kleines Gebäude, Abbruch).',
        ];
    }
}
