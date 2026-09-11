<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Schritt 6: Texte (Datenvertrag Abschnitt 2.7 und 5.6).
 */
class Step6TexteRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Listing $listing */
        $listing = $this->route('listing');

        return $this->user()?->can('update', $listing) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titel' => ['nullable', 'string', 'max:100'],
            'beschreibung_objekt' => ['nullable', 'string', 'max:8000'],
            'beschreibung_ausstattung' => ['nullable', 'string', 'max:8000'],
            'beschreibung_lage' => ['nullable', 'string', 'max:8000'],
            'beschreibung_sonstiges' => ['nullable', 'string', 'max:8000'],
        ];
    }
}
