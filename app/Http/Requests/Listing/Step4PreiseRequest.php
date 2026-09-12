<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Enums\Energietraeger;
use App\Enums\HeizkostenStruktur;
use App\Enums\HeizkostenVersorgung;
use App\Enums\Heizungsart;
use App\Enums\ProvisionTyp;
use App\Enums\StellplatzModus;
use App\Enums\Waermeabgabe;
use App\Enums\Warmwasserbereitung;
use App\Models\Listing;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Schritt 4: Preise und Heizung (Masterprompt-Abgleich B.1 Schritt 4, B.2).
 *
 * Beträge kommen als deutsch formatierte Zeichenketten herein; Money::parse
 * übernimmt der Controller. Die Warmmietenlogik selbst (Fälle A, B, D, E, F
 * aus Datenvertrag Abschnitt 3) prüft der RentCalculator serverseitig beim
 * Speichern, nicht hier. Provisionstext und -bestätigung sind hier bewusst
 * nicht Pflicht: ein Entwurf darf ohne sie gespeichert werden, die
 * Veröffentlichung blockiert CompletenessCheck erst bei Bedarf.
 */
class Step4PreiseRequest extends FormRequest
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
        /** @var Listing $listing */
        $listing = $this->route('listing');

        return array_merge($this->preisRegeln($listing->istMiete(), false), $this->heizungRegeln(false));
    }

    /**
     * @return array<string, mixed>
     */
    public static function autosaveRegeln(Listing $listing): array
    {
        $instanz = new self;

        return array_merge($instanz->preisRegeln($listing->istMiete(), true), $instanz->heizungRegeln(true));
    }

    /**
     * @return array<string, mixed>
     */
    private function preisRegeln(bool $istMiete, bool $autosave): array
    {
        $betrag = self::betragsRegel();
        $pflicht = $autosave ? ['sometimes', 'nullable', 'string', $betrag] : ['nullable', 'string', $betrag];
        $optional = $autosave ? ['sometimes', 'nullable', 'string', $betrag] : ['nullable', 'string', $betrag];
        $auswahl = fn (string $enum) => $autosave ? ['sometimes', 'nullable', Rule::enum($enum)] : ['nullable', Rule::enum($enum)];

        $gemeinsam = [
            'stellplatz_modus' => $auswahl(StellplatzModus::class),
            'stellplatz_miete' => $optional,
            'stellplatz_kaufpreis' => $optional,
            'provision_typ' => $auswahl(ProvisionTyp::class),
            'provision_text' => $autosave ? ['sometimes', 'nullable', 'string', 'max:255'] : ['nullable', 'string', 'max:255'],
            'provision_bestaetigt' => ['sometimes', 'boolean'],
        ];

        if ($istMiete) {
            return array_merge($gemeinsam, [
                'kaltmiete' => $pflicht,
                'nebenkosten' => $pflicht,
                'heizkosten' => $optional,
                'heizkosten_struktur' => $auswahl(HeizkostenStruktur::class),
                'kaution' => $optional,
            ]);
        }

        return array_merge($gemeinsam, [
            'kaufpreis' => $pflicht,
            'hausgeld' => $optional,
            'mieteinnahmen_ist' => $optional,
            'heizkosten_versorgung' => $auswahl(HeizkostenVersorgung::class),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function heizungRegeln(bool $autosave): array
    {
        $auswahl = fn (string $enum) => $autosave ? ['sometimes', 'nullable', Rule::enum($enum)] : ['nullable', Rule::enum($enum)];

        return [
            'heizungsart' => $auswahl(Heizungsart::class),
            'energietraeger' => $auswahl(Energietraeger::class),
            'heizung_waermeabgabe' => $auswahl(Waermeabgabe::class),
            'heizung_warmwasser' => $auswahl(Warmwasserbereitung::class),
        ];
    }

    public static function betragsRegel(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            $cent = Money::parse((string) $value);

            if ($cent === null || $cent < 0) {
                $fail('Bitte einen Betrag im Format 1.234,56 eingeben.');
            }
        };
    }
}
