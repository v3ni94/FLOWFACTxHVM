<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Ausweistyp;
use App\Enums\Effizienzklasse;
use App\Enums\EnergieausweisStatus;
use App\Models\Listing;
use App\Models\ListingEnergy;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingEnergy>
 */
class ListingEnergyFactory extends Factory
{
    protected $model = ListingEnergy::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'status' => EnergieausweisStatus::Vorhanden,
            'ausweistyp' => Ausweistyp::Verbrauch,
            'ausstellungsdatum' => '2024-03-15',
            'kennwert_kwh' => 120.5,
            'kennwert_strom_kwh' => null,
            'effizienzklasse' => Effizienzklasse::C,
            'baujahr_anlage' => 2005,
            'gueltig_bis' => null,
            'enthaelt_warmwasser' => true,
            'ausnahme_begruendung' => null,
            'ausnahme_bestaetigt_von_user_id' => null,
            'ausnahme_bestaetigt_at' => null,
        ];
    }

    public function vorhanden(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EnergieausweisStatus::Vorhanden,
        ]);
    }

    public function nochNichtVorhanden(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EnergieausweisStatus::NochNichtVorhanden,
            'ausweistyp' => null,
            'ausstellungsdatum' => null,
            'kennwert_kwh' => null,
            'effizienzklasse' => null,
        ]);
    }

    public function beauftragt(): static
    {
        return $this->nochNichtVorhanden()->state(fn (array $attributes): array => [
            'status' => EnergieausweisStatus::Beauftragt,
        ]);
    }

    public function ausnahmeZuPruefen(): static
    {
        return $this->nochNichtVorhanden()->state(fn (array $attributes): array => [
            'status' => EnergieausweisStatus::AusnahmeZuPruefen,
        ]);
    }

    public function ausnahmeBestaetigt(?User $admin = null): static
    {
        return $this->ausnahmeZuPruefen()->state(fn (array $attributes): array => [
            'ausnahme_begruendung' => 'Baudenkmal, Ausweispflicht entfällt nach Einschätzung des Eigentümers.',
            'ausnahme_bestaetigt_von_user_id' => $admin?->id ?? User::factory()->admin(),
            'ausnahme_bestaetigt_at' => now(),
        ]);
    }
}
