<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Neue Reihenfolge der Medien eines Objekts (Datenvertrag Abschnitt 2.6).
 *
 * `sortierung` ist in der Datenbank ein smallInteger (Prüfbericht
 * 2026-09-11, Befund 17): ohne Validierung führen Werte über 32767 auf
 * MariaDB im Strict-Modus zu einem SQL-Fehler und HTTP 500 statt zu einer
 * Formularfehlermeldung.
 */
class MediaSortRequest extends FormRequest
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
            'reihenfolge' => ['sometimes', 'array'],
            'reihenfolge.*' => ['integer', 'min:0', 'max:32767'],
        ];
    }
}
