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
 * Prüfbericht 2026-09-11, Befund 12 (weiterhin gültig unter der neuen
 * Schrittfolge, Masterprompt-Abgleich B.1): rein interne Änderungen
 * (listing_internals, in Schritt 2 miterfasst) und ein leerer Autosave dürfen
 * den Übertragungsstatus einer bereits übertragenen FLOWFACT-Verknüpfung
 * nicht auf "geändert seit Übertragung" kippen. AbstractStep::saveWithTracking
 * ermittelt dafür den Inhalts-Hash vor und nach der Änderung.
 */
final class WizardChangeTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_rein_interne_angaben_und_ein_leerer_autosave_kippen_den_uebertragungsstatus_nicht(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->vollstaendig()->create(['status' => ListingStatus::Veroeffentlicht]);
        ListingFlowfactLink::factory()->create(['listing_id' => $listing->id, 'flowfact_entity_id' => 'ent-1', 'sync_status' => SyncStatus::Uebertragen]);

        // Autosave darf einzelne interne Felder speichern, ohne die
        // Adressfelder mitzusenden (sometimes/nullable, Masterprompt-Abgleich B.1).
        $this->actingAs($user)->patchJson(
            route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 2]),
            ['interne_notizen' => 'Schlüssel beim Hausmeister']
        )->assertOk();

        self::assertSame('Schlüssel beim Hausmeister', $listing->internal()->first()->interne_notizen);
        self::assertSame(SyncStatus::Uebertragen, $listing->flowfactLink()->first()->sync_status);
        self::assertNull($listing->fresh()->inhalt_geaendert_at);

        // Ein Autosave ohne jede Angabe (z. B. Schritt 6, keine eigenen Felder).
        $this->actingAs($user)->patchJson(
            route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 6]),
            []
        )->assertOk();

        self::assertSame(SyncStatus::Uebertragen, $listing->flowfactLink()->first()->sync_status);
        self::assertNull($listing->fresh()->inhalt_geaendert_at);
    }

    public function test_eine_tatsaechliche_inhaltliche_aenderung_kippt_den_uebertragungsstatus_weiterhin(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->vollstaendig()->create(['status' => ListingStatus::Veroeffentlicht]);
        ListingFlowfactLink::factory()->create(['listing_id' => $listing->id, 'flowfact_entity_id' => 'ent-1', 'sync_status' => SyncStatus::Uebertragen]);

        $this->actingAs($user)->post(route('app.listings.step.store', ['listing' => $listing, 'schritt' => 2]), [
            'aktion' => 'speichern',
            'strasse' => 'Eine ganz andere Straße',
            'hausnummer' => $listing->hausnummer,
            'plz' => $listing->plz,
            'ort' => $listing->ort,
            'land' => $listing->land,
            'adress_freigabe' => $listing->adress_freigabe->value,
        ])->assertRedirect();

        self::assertSame(SyncStatus::GeaendertSeitUebertragung, $listing->flowfactLink()->first()->sync_status);
        self::assertNotNull($listing->fresh()->inhalt_geaendert_at);
    }
}
