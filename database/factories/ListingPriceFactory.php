<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProvisionTyp;
use App\Models\Listing;
use App\Models\ListingPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingPrice>
 */
class ListingPriceFactory extends Factory
{
    protected $model = ListingPrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'kaltmiete_cent' => 80_000,
            'nebenkosten_cent' => 20_000,
            'heizkosten_cent' => 10_000,
            'heizkosten_in_nebenkosten_enthalten' => false,
            'warmmiete_cent' => 110_000,
            'kaution_cent' => 240_000,
            'stellplatz_miete_cent' => null,
            'kaufpreis_cent' => null,
            'hausgeld_cent' => null,
            'stellplatz_kaufpreis_cent' => null,
            'mieteinnahmen_ist_cent' => null,
            'provision_typ' => ProvisionTyp::Provisionsfrei,
            'provision_text' => null,
        ];
    }

    public function miete(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kaltmiete_cent' => 80_000,
            'nebenkosten_cent' => 20_000,
            'kaufpreis_cent' => null,
        ]);
    }

    public function kauf(): static
    {
        return $this->state(fn (array $attributes): array => [
            'kaltmiete_cent' => null,
            'nebenkosten_cent' => null,
            'heizkosten_cent' => null,
            'warmmiete_cent' => null,
            'kaution_cent' => null,
            'kaufpreis_cent' => 32_500_00,
        ]);
    }
}
