<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Domain\Listing\ListingChangeTracker;
use App\Enums\ListingStatus;
use App\Enums\SyncStatus;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ListingChangeTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_recordchange_setzt_inhalt_geaendert_at(): void
    {
        $listing = Listing::factory()->vollstaendig()->create([
            'inhalt_geaendert_at' => null,
        ]);

        app(ListingChangeTracker::class)->recordChange($listing);

        $this->assertNotNull($listing->fresh()->inhalt_geaendert_at);
    }

    public function test_ein_bereites_objekt_faellt_bei_unvollstaendigkeit_auf_entwurf_zurueck(): void
    {
        $listing = Listing::factory()->vollstaendig()->create([
            'status' => ListingStatus::Bereit,
        ]);

        // Der Titel wird entfernt, das Objekt ist damit nicht mehr vollständig.
        $listing->titel = null;
        $listing->save();

        app(ListingChangeTracker::class)->recordChange($listing);

        $this->assertSame(ListingStatus::Entwurf, $listing->fresh()->status);
    }

    public function test_ein_bereites_und_weiterhin_vollstaendiges_objekt_bleibt_bereit(): void
    {
        $listing = Listing::factory()->vollstaendig()->create([
            'status' => ListingStatus::Bereit,
        ]);

        app(ListingChangeTracker::class)->recordChange($listing);

        $this->assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    public function test_eine_uebertragene_verknuepfung_wird_als_geaendert_markiert(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();

        $listing->flowfactLink()->create([
            'flowfact_entity_id' => 'ff-1',
            'flowfact_schema' => 'immobilie',
            'sync_status' => SyncStatus::Uebertragen,
            'letzte_uebertragung_at' => now(),
        ]);

        app(ListingChangeTracker::class)->recordChange($listing);

        $this->assertSame(
            SyncStatus::GeaendertSeitUebertragung,
            $listing->flowfactLink->fresh()->sync_status,
        );
    }

    public function test_eine_noch_nicht_uebertragene_verknuepfung_bleibt_unveraendert(): void
    {
        $listing = Listing::factory()->vollstaendig()->create();

        $listing->flowfactLink()->create([
            'flowfact_schema' => 'immobilie',
            'sync_status' => SyncStatus::NichtUebertragen,
        ]);

        app(ListingChangeTracker::class)->recordChange($listing);

        $this->assertSame(
            SyncStatus::NichtUebertragen,
            $listing->flowfactLink->fresh()->sync_status,
        );
    }
}
