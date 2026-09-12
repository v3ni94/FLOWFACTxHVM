<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MediaTyp;
use App\Models\Listing;
use App\Models\ListingMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ListingMedia>
 */
class ListingMediaFactory extends Factory
{
    protected $model = ListingMedia::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'typ' => MediaTyp::Bild,
            'dateiname_original' => 'bild.jpg',
            'pfad' => 'listings/'.Str::uuid()->toString().'/bild.jpg',
            'mime' => 'image/jpeg',
            'groesse_bytes' => 204_800,
            'breite' => 1600,
            'hoehe' => 1200,
            'sortierung' => 0,
            'titel' => null,
            'rotation' => 0,
            'im_inserat' => true,
            'flowfact_multimedia_id' => null,
            'pruefsumme_sha256' => hash('sha256', (string) Str::uuid()),
        ];
    }

    public function bild(): static
    {
        return $this->state(fn (array $attributes): array => [
            'typ' => MediaTyp::Bild,
        ]);
    }

    public function grundriss(): static
    {
        return $this->state(fn (array $attributes): array => [
            'typ' => MediaTyp::Grundriss,
        ]);
    }

    public function dokument(): static
    {
        return $this->state(fn (array $attributes): array => [
            'typ' => MediaTyp::Dokument,
            'dateiname_original' => 'expose.pdf',
            'mime' => 'application/pdf',
            'breite' => null,
            'hoehe' => null,
        ]);
    }

    public function energieausweis(): static
    {
        return $this->dokument()->state(fn (array $attributes): array => [
            'typ' => MediaTyp::Energieausweis,
            'dateiname_original' => 'energieausweis.pdf',
        ]);
    }

    public function freigegeben(bool $freigegeben = true): static
    {
        return $this->state(fn (array $attributes): array => [
            'freigegeben' => $freigegeben,
        ]);
    }
}
