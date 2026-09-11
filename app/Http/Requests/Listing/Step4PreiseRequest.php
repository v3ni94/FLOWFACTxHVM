<?php

declare(strict_types=1);

namespace App\Http\Requests\Listing;

use App\Enums\HeizkostenVersorgung;
use App\Enums\ProvisionTyp;
use App\Models\Listing;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Schritt 4: Preise (Datenvertrag Abschnitt 2.3 und 3). Beträge kommen als
 * deutsch formatierte Zeichenketten herein und werden hier nur auf das
 * Format geprüft; Money::parse übernimmt der Controller. Die eigentliche
 * Preislogik (Warmmiete, Fälle E und F) prüft der RentCalculator serverseitig
 * beim Speichern, nicht hier.
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

        $betrag = $this->betragsRegel();

        if ($listing->istMiete()) {
            return [
                'kaltmiete' => ['required', 'string', $betrag],
                'nebenkosten' => ['required', 'string', $betrag],
                'heizkosten' => ['nullable', 'string', $betrag],
                'heizkosten_in_nebenkosten_enthalten' => ['sometimes', 'boolean'],
                'heizkosten_versorgung' => ['required', Rule::enum(HeizkostenVersorgung::class)],
                'kaution' => ['nullable', 'string', $betrag],
                'stellplatz_miete' => ['nullable', 'string', $betrag],
                'provision_typ' => ['required', Rule::enum(ProvisionTyp::class)],
                'provision_text' => ['nullable', 'string', 'max:255'],
            ];
        }

        return [
            'kaufpreis' => ['required', 'string', $betrag],
            'hausgeld' => ['nullable', 'string', $betrag],
            'stellplatz_kaufpreis' => ['nullable', 'string', $betrag],
            'mieteinnahmen_ist' => ['nullable', 'string', $betrag],
            'provision_typ' => ['required', Rule::enum(ProvisionTyp::class)],
            'provision_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function betragsRegel(): Closure
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
