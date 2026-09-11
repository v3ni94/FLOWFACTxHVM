<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Enums\Ausweistyp;
use App\Enums\Effizienzklasse;
use App\Enums\EnergieausweisStatus;
use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Schritt 3: Energieausweis (Datenvertrag Abschnitt 2.4 und 5.3). Kennwert
 * und Ausweistyp sind nur bei Status "liegt_vor" Pflicht.
 */
class Step3EnergieRequest extends FormRequest
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
        $liegtVor = $this->input('status') === EnergieausweisStatus::LiegtVor->value;

        return [
            'status' => ['nullable', Rule::enum(EnergieausweisStatus::class)],
            'ausweistyp' => [$liegtVor ? 'required' : 'nullable', Rule::enum(Ausweistyp::class)],
            'kennwert_kwh' => [$liegtVor ? 'required' : 'nullable', 'numeric', 'min:0', 'max:9999.9'],
            'effizienzklasse' => ['nullable', Rule::enum(Effizienzklasse::class)],
            'baujahr_anlage' => ['nullable', 'integer', 'min:1800', 'max:'.((int) now()->format('Y') + 3)],
            'gueltig_bis' => ['nullable', 'date'],
            'enthaelt_warmwasser' => ['sometimes', 'boolean'],
        ];
    }
}
