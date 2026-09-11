<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PortalStatus;
use App\Models\Listing;
use App\Models\ListingPortalPublication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingPortalPublication>
 */
class ListingPortalPublicationFactory extends Factory
{
    protected $model = ListingPortalPublication::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'portal_id' => 'immoscout24',
            'portal_name' => 'ImmoScout24',
            'status' => PortalStatus::NichtVeroeffentlicht,
            'angefordert_at' => null,
            'bestaetigt_at' => null,
            'zurueckgezogen_at' => null,
            'letzte_pruefung_at' => null,
            'letzter_fehler' => null,
        ];
    }
}
