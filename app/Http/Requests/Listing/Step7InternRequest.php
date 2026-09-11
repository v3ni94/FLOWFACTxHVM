<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Schritt 7: Interne Daten (Datenvertrag Abschnitt 2.5, ADR-003). Diese
 * Felder gehen nie an FLOWFACT oder Portale.
 */
class Step7InternRequest extends FormRequest
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
            'eigentuemer_name' => ['nullable', 'string', 'max:255'],
            'eigentuemer_kontakt' => ['nullable', 'string', 'max:4000'],
            'verwaltungsobjekt_referenz' => ['nullable', 'string', 'max:255'],
            'interne_notizen' => ['nullable', 'string', 'max:4000'],
            'schluessel_hinweis' => ['nullable', 'string', 'max:4000'],
            'besichtigung_intern' => ['nullable', 'string', 'max:4000'],
            'kalkulation_notiz' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
