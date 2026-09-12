<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Domain\Listing\Merkmale;
use App\Enums\Ausweistyp;
use App\Enums\Effizienzklasse;
use App\Enums\EnergieausweisStatus;
use App\Enums\StellplatzTyp;
use App\Models\Listing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Schritt 5: Ausstattung und Energieausweis (Masterprompt-Abgleich B.1
 * Schritt 5, B.2, B.5). Merkmale werden als eigene Feldnamen
 * "merkmal_{schluessel}" übertragen (nie als verschachteltes Array), damit
 * dieselbe Feldliste unverändert im normalen POST wie im JSON-Body des
 * Autosave funktioniert.
 */
class Step5AusstattungRequest extends FormRequest
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
        return $this->regeln(false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function autosaveRegeln(): array
    {
        return (new self)->regeln(true);
    }

    /**
     * @return array<string, mixed>
     */
    private function regeln(bool $autosave): array
    {
        $praefix = $autosave ? ['sometimes', 'nullable'] : ['nullable'];
        $auswahl = fn (string $enum) => array_merge($praefix, [Rule::enum($enum)]);

        $regeln = [
            'einbaukueche_mitvermietet' => ['sometimes', 'boolean'],
            'stellplatz_typ' => $auswahl(StellplatzTyp::class),
            'stellplatz_anzahl' => array_merge($praefix, ['integer', 'min:0', 'max:99']),
            'energieausweis_status' => $auswahl(EnergieausweisStatus::class),
            'ausweistyp' => $auswahl(Ausweistyp::class),
            'ausstellungsdatum' => array_merge($praefix, ['date']),
            'gueltig_bis' => array_merge($praefix, ['date']),
            'kennwert_kwh' => array_merge($praefix, ['numeric', 'min:0', 'max:9999.9']),
            'kennwert_strom_kwh' => array_merge($praefix, ['numeric', 'min:0', 'max:9999.9']),
            'effizienzklasse' => $auswahl(Effizienzklasse::class),
            'baujahr_anlage' => array_merge($praefix, ['integer', 'min:1800', 'max:'.((int) now()->format('Y') + 3)]),
            'enthaelt_warmwasser' => ['sometimes', 'boolean'],
            'ausnahme_begruendung' => array_merge($praefix, ['string', 'max:2000']),
        ];

        foreach (Merkmale::schluessel() as $schluessel) {
            $regeln['merkmal_'.$schluessel] = array_merge($praefix, ['in:ja,nein,unbekannt']);
        }

        return $regeln;
    }
}
