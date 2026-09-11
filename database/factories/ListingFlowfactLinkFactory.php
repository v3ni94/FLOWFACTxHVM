<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SyncStatus;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ListingFlowfactLink>
 */
class ListingFlowfactLinkFactory extends Factory
{
    protected $model = ListingFlowfactLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'listing_id' => Listing::factory(),
            'flowfact_entity_id' => null,
            'flowfact_schema' => 'wohnung_miete',
            'sync_status' => SyncStatus::NichtUebertragen,
            'letzte_uebertragung_at' => null,
            'letzter_fehler' => null,
            'uebertragener_inhalt_hash' => null,
            'sperre_bis' => null,
        ];
    }
}
