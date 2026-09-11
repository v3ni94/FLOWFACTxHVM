<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Enums\MediaTyp;
use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload von Bildern, Grundrissen und Dokumenten in Schritt 5 (Datenvertrag
 * Abschnitt 2.6, ADR-012). Größe, Anzahl und Dateityp prüft der
 * MediaUploadService anhand des tatsächlichen Inhalts; hier werden nur die
 * grundlegende Formstruktur und die Auswahl des Medientyps geprüft.
 */
class MediaStoreRequest extends FormRequest
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
            'typ' => ['required', Rule::enum(MediaTyp::class)],
            'dateien' => ['required', 'array', 'min:1'],
            'dateien.*' => ['required', 'file'],
        ];
    }
}
