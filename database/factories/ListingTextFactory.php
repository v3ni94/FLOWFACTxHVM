<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TextFeld;
use App\Enums\TextQuelle;
use App\Models\Listing;
use App\Models\ListingText;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingText>
 */
class ListingTextFactory extends Factory
{
    protected $model = ListingText::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'feld' => TextFeld::BeschreibungObjekt,
            'quelle' => TextQuelle::Manuell,
            'modell' => null,
            'inhalt' => 'Diese gepflegte Wohnung überzeugt durch einen hellen Zuschnitt und eine ruhige Lage in Erkelenz.',
            'uebernommen' => false,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function ki(): static
    {
        return $this->state(fn (array $attributes): array => [
            'quelle' => TextQuelle::Ki,
            'modell' => 'fake',
        ]);
    }
}
