<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Listing;
use App\Models\ListingInternal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingInternal>
 */
class ListingInternalFactory extends Factory
{
    protected $model = ListingInternal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'eigentuemer_name' => 'Testverwaltung Erkelenz GbR',
            'eigentuemer_kontakt' => 'Nur zu Testzwecken.',
            'verwaltungsobjekt_referenz' => 'WEG-TEST-001',
            'interne_notizen' => 'Interner Testvermerk, nicht Teil des Inserats.',
            'schluessel_hinweis' => 'Schlüssel im Büro.',
            'besichtigung_intern' => null,
            'kalkulation_notiz' => null,
        ];
    }
}
