<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Enums\Objektart;
use App\Enums\Vermarktungsart;
use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Schritt 1: Grunddaten (Datenvertrag Abschnitt 5.1). Entwürfe dürfen leere
 * Adressfelder haben, Format und Wertebereich werden aber immer geprüft.
 */
class Step1GrunddatenRequest extends FormRequest
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
            'vermarktungsart' => ['required', Rule::enum(Vermarktungsart::class)],
            'objektart' => ['required', Rule::enum(Objektart::class)],
            'strasse' => ['nullable', 'string', 'max:255'],
            'hausnummer' => ['nullable', 'string', 'max:20'],
            'plz' => ['nullable', 'string', 'max:5', 'regex:/^\d{4,5}$/'],
            'ort' => ['nullable', 'string', 'max:255'],
            'land' => ['nullable', 'string', 'size:2'],
            'adresse_im_inserat_anzeigen' => ['sometimes', 'boolean'],
        ];
    }
}
