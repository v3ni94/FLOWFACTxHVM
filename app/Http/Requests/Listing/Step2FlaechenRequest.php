<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Enums\Ausstattungsqualitaet;
use App\Enums\Energietraeger;
use App\Enums\HeizkostenVersorgung;
use App\Enums\Heizungsart;
use App\Enums\StellplatzTyp;
use App\Enums\VerfuegbarAbTyp;
use App\Enums\Zustand;
use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Schritt 2: Flächen und Ausstattung (Datenvertrag Abschnitt 5.2).
 */
class Step2FlaechenRequest extends FormRequest
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
        $aktuellesJahr = (int) now()->format('Y');

        return [
            'wohnflaeche_qm' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'nutzflaeche_qm' => ['nullable', 'numeric', 'min:0', 'max:99999.99'],
            'grundstuecksflaeche_qm' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'zimmer' => ['nullable', 'numeric', 'min:0', 'max:999.5'],
            'schlafzimmer' => ['nullable', 'integer', 'min:0', 'max:99'],
            'badezimmer' => ['nullable', 'integer', 'min:0', 'max:99'],
            'etage' => ['nullable', 'integer', 'min:-9', 'max:200'],
            'etagen_gesamt' => ['nullable', 'integer', 'min:0', 'max:200'],
            'baujahr' => ['nullable', 'integer', 'min:1800', 'max:'.($aktuellesJahr + 3)],
            'zustand' => ['nullable', Rule::enum(Zustand::class)],
            'ausstattungsqualitaet' => ['nullable', Rule::enum(Ausstattungsqualitaet::class)],
            'heizungsart' => ['nullable', Rule::enum(Heizungsart::class)],
            'energietraeger' => ['nullable', Rule::enum(Energietraeger::class)],
            'heizkosten_versorgung' => ['nullable', Rule::enum(HeizkostenVersorgung::class)],
            'verfuegbar_ab_typ' => ['nullable', Rule::enum(VerfuegbarAbTyp::class)],
            'verfuegbar_ab_datum' => ['nullable', 'date'],
            'ausstattung' => ['sometimes', 'array'],
            'ausstattung.*' => ['boolean'],
            'stellplatz_typ' => ['nullable', Rule::enum(StellplatzTyp::class)],
            'stellplatz_anzahl' => ['nullable', 'integer', 'min:0', 'max:99'],
        ];
    }
}
