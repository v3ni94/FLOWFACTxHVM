<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\HeizkostenStruktur;
use App\Enums\ProvisionTyp;
use App\Enums\StellplatzModus;
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
            'heizkosten_struktur' => HeizkostenStruktur::Zusaetzlich,
            'warmmiete_cent' => 110_000,
            'kaution_cent' => 240_000,
            'stellplatz_miete_cent' => null,
            'stellplatz_modus' => StellplatzModus::Keiner,
            'kaufpreis_cent' => null,
            'hausgeld_cent' => null,
            'stellplatz_kaufpreis_cent' => null,
            'stellplatz_im_kaufpreis' => null,
            'mieteinnahmen_ist_cent' => null,
            'provision_typ' => ProvisionTyp::Provisionsfrei,
            'provision_text' => null,
            'provision_bestaetigt' => false,
        ];
    }

    public function provisionspflichtig(bool $bestaetigt = true): static
    {
        return $this->state(fn (array $attributes): array => [
            'provision_typ' => ProvisionTyp::Provisionspflichtig,
            'provision_text' => '3,57 % inkl. MwSt.',
            'provision_bestaetigt' => $bestaetigt,
        ]);
    }

    public function stellplatzOptional(int $stellplatzMieteCent = 5_000): static
    {
        return $this->state(fn (array $attributes): array => [
            'stellplatz_modus' => StellplatzModus::Optional,
            'stellplatz_miete_cent' => $stellplatzMieteCent,
        ]);
    }

    public function stellplatzPflichtZusaetzlich(int $stellplatzMieteCent = 5_000): static
    {
        return $this->state(fn (array $attributes): array => [
            'stellplatz_modus' => StellplatzModus::PflichtZusaetzlich,
            'stellplatz_miete_cent' => $stellplatzMieteCent,
        ]);
    }

    public function stellplatzPflichtEnthalten(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stellplatz_modus' => StellplatzModus::PflichtEnthalten,
            'stellplatz_miete_cent' => null,
        ]);
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
            'heizkosten_struktur' => null,
            'kaufpreis_cent' => 32_500_00,
        ]);
    }
}
