<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReleaseAktion;
use App\Models\Listing;
use App\Models\ListingRelease;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingRelease>
 */
class ListingReleaseFactory extends Factory
{
    protected $model = ListingRelease::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'version' => 1,
            'payload_json' => [
                'listing' => ['titel' => 'Gepflegte 3-Zimmer-Wohnung in Erkelenz'],
                'price' => ['kaltmiete_cent' => 80_000],
                'energy' => null,
            ],
            'medien_json' => [],
            'portale_json' => ['immoscout24'],
            'inhalt_hash' => hash('sha256', 'release-'.fake()->uuid()),
            'freigegeben_von_user_id' => User::factory(),
            'freigegeben_at' => now(),
            'aktion' => ReleaseAktion::Veroeffentlichen,
        ];
    }

    public function flowfactSpeichern(): static
    {
        return $this->state(fn (array $attributes): array => [
            'aktion' => ReleaseAktion::FlowfactSpeichern,
            'portale_json' => [],
        ]);
    }

    public function deaktivieren(): static
    {
        return $this->state(fn (array $attributes): array => [
            'aktion' => ReleaseAktion::Deaktivieren,
        ]);
    }
}
