<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Ausweistyp;
use App\Enums\Effizienzklasse;
use App\Enums\EnergieausweisStatus;
use App\Models\Listing;
use App\Models\ListingEnergy;
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
            'status' => EnergieausweisStatus::LiegtVor,
            'ausweistyp' => Ausweistyp::Verbrauch,
            'kennwert_kwh' => 120.5,
            'effizienzklasse' => Effizienzklasse::C,
            'baujahr_anlage' => 2005,
            'gueltig_bis' => null,
            'enthaelt_warmwasser' => true,
        ];
    }
}
