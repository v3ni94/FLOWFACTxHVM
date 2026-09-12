<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Schritt 8: Beschreibungen (Masterprompt-Abgleich B.1 Schritt 8, Masterprompt
 * Abschnitt 16). Alle vier Felder sind optional, ein Entwurf darf ohne
 * Beschreibungen gespeichert werden; die Vollständigkeitsprüfung verlangt
 * beschreibung_objekt erst vor der Veröffentlichung.
 */
class Step8BeschreibungenRequest extends FormRequest
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
            'beschreibung_objekt' => ['nullable', 'string'],
            'beschreibung_ausstattung' => ['nullable', 'string'],
            'beschreibung_lage' => ['nullable', 'string'],
            'beschreibung_sonstiges' => ['nullable', 'string'],
        ];
    }
}
