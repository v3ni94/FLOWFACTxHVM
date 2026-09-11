<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Domain\Listing\ListingChangeTracker;
use App\Domain\Listing\ListingContentHasher;
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

    /**
     * Prüfbericht 2026-09-11, Befund 12: Mit übergebenem "Vorher"-Hash darf
     * recordChange den Übertragungsstatus nur kippen, wenn sich tatsächlich
     * ein Inseratsfeld geändert hat. Rein interne Daten (listing_internals)
     * sind nicht Teil des Hashes und dürfen den Status nie beeinflussen
     * (z. B. Schritt 7 des Assistenten).
     */
    public function test_recordchange_mit_unveraendertem_hash_markiert_nichts_als_geaendert(): void
    {
        $listing = Listing::factory()->vollstaendig()->create([
            'inhalt_geaendert_at' => null,
            'status' => ListingStatus::Bereit,
        ]);

        $listing->flowfactLink()->create([
            'flowfact_entity_id' => 'ff-1',
            'flowfact_schema' => 'immobilie',
            'sync_status' => SyncStatus::Uebertragen,
            'letzte_uebertragung_at' => now(),
        ]);

        $vorherHash = app(ListingContentHasher::class)->hash($listing->fresh(['price', 'energy', 'media']));

        // Nur ein internes Feld ändert sich, kein Inseratsfeld.
        $listing->internal()->create(['interne_notizen' => 'Nur für die Verwaltung']);

        app(ListingChangeTracker::class)->recordChange($listing, $vorherHash);

        $listing->refresh();
        self::assertNull($listing->inhalt_geaendert_at);
        self::assertSame(SyncStatus::Uebertragen, $listing->flowfactLink->fresh()->sync_status);
        self::assertSame(ListingStatus::Bereit, $listing->status);
    }

    public function test_recordchange_mit_abweichendem_hash_markiert_die_aenderung_weiterhin(): void
    {
        $listing = Listing::factory()->vollstaendig()->create([
            'inhalt_geaendert_at' => null,
        ]);

        $listing->flowfactLink()->create([
            'flowfact_entity_id' => 'ff-1',
            'flowfact_schema' => 'immobilie',
            'sync_status' => SyncStatus::Uebertragen,
            'letzte_uebertragung_at' => now(),
        ]);

        app(ListingChangeTracker::class)->recordChange($listing, 'ein-anderer-hash-als-zuvor');

        $listing->refresh();
        self::assertNotNull($listing->inhalt_geaendert_at);
        self::assertSame(SyncStatus::GeaendertSeitUebertragung, $listing->flowfactLink->fresh()->sync_status);
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
