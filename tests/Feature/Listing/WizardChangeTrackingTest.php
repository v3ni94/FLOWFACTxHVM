<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\ListingStatus;
use App\Enums\SyncStatus;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prüfbericht 2026-09-11, Befund 12: Speichern interner Daten (Schritt 7)
 * oder ein leerer POST auf Schritt 5 oder 8 setzte `inhalt_geaendert_at` und
 * kippte den Übertragungsstatus auf "Geändert seit Übertragung", obwohl sich
 * kein Inseratsfeld geändert hatte. Portiert aus
 * Poc08SecurityTest::test_interne_daten_kippen_den_uebertragungsstatus mit
 * umgekehrter Erwartung.
 */
final class WizardChangeTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_interne_daten_und_ein_leerer_schritt_kippen_den_uebertragungsstatus_nicht(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->vollstaendig()->create(['status' => ListingStatus::Veroeffentlicht]);
        ListingFlowfactLink::factory()->create(['listing_id' => $listing->id, 'flowfact_entity_id' => 'ent-1', 'sync_status' => SyncStatus::Uebertragen]);

        $this->actingAs($user)->post(route('app.listings.step.store', ['listing' => $listing, 'schritt' => 7]), ['interne_notizen' => 'Schlüssel beim Hausmeister'])->assertRedirect();
        self::assertSame(SyncStatus::Uebertragen, $listing->flowfactLink()->first()->sync_status);
        self::assertNull($listing->fresh()->inhalt_geaendert_at);

        $this->actingAs($user)->post(route('app.listings.step.store', ['listing' => $listing, 'schritt' => 8]), [])->assertRedirect();
        self::assertSame(SyncStatus::Uebertragen, $listing->flowfactLink()->first()->sync_status);
        self::assertNull($listing->fresh()->inhalt_geaendert_at);
    }

    public function test_eine_tatsaechliche_inhaltliche_aenderung_kippt_den_uebertragungsstatus_weiterhin(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->vollstaendig()->create(['status' => ListingStatus::Veroeffentlicht]);
        ListingFlowfactLink::factory()->create(['listing_id' => $listing->id, 'flowfact_entity_id' => 'ent-1', 'sync_status' => SyncStatus::Uebertragen]);

        $this->actingAs($user)->post(route('app.listings.step.store', ['listing' => $listing, 'schritt' => 1]), [
            'vermarktungsart' => $listing->vermarktungsart->value,
            'objektart' => $listing->objektart->value,
            'strasse' => 'Eine ganz andere Straße',
            'hausnummer' => $listing->hausnummer,
            'plz' => $listing->plz,
            'ort' => $listing->ort,
            'land' => $listing->land,
        ])->assertRedirect();

        self::assertSame(SyncStatus::GeaendertSeitUebertragung, $listing->flowfactLink()->first()->sync_status);
        self::assertNotNull($listing->fresh()->inhalt_geaendert_at);
    }
}
