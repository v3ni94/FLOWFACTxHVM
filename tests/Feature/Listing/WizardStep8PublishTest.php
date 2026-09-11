<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\ListingStatus;
use App\Flowfact\Sync\NullPublishingService;
use App\Flowfact\Sync\PublishingService;
use App\Flowfact\Sync\PublishResult;
use App\Flowfact\Sync\SyncResult;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WizardStep8PublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_die_pruefseite_zeigt_die_fehlenden_felder_eines_unvollstaendigen_objekts(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['titel' => null]);

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 8]));

        $response->assertOk();
        $response->assertSee('Titel');
    }

    public function test_interne_daten_erscheinen_niemals_auf_der_pruefseite(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create();

        $listing->internal()->create([
            'eigentuemer_name' => 'INTERN-MARKER-NAME',
            'eigentuemer_kontakt' => 'INTERN-MARKER-KONTAKT',
            'verwaltungsobjekt_referenz' => 'INTERN-MARKER-REFERENZ',
            'interne_notizen' => 'INTERN-MARKER-NOTIZ',
            'schluessel_hinweis' => 'INTERN-MARKER-SCHLUESSEL',
            'besichtigung_intern' => 'INTERN-MARKER-BESICHTIGUNG',
            'kalkulation_notiz' => 'INTERN-MARKER-KALKULATION',
        ]);

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 8]));

        $response->assertOk();
        $response->assertDontSee('INTERN-MARKER-NAME');
        $response->assertDontSee('INTERN-MARKER-KONTAKT');
        $response->assertDontSee('INTERN-MARKER-REFERENZ');
        $response->assertDontSee('INTERN-MARKER-NOTIZ');
        $response->assertDontSee('INTERN-MARKER-SCHLUESSEL');
        $response->assertDontSee('INTERN-MARKER-BESICHTIGUNG');
        $response->assertDontSee('INTERN-MARKER-KALKULATION');
    }

    public function test_die_veroeffentlichung_eines_unvollstaendigen_objekts_wird_serverseitig_blockiert(): void
    {
        $user = User::factory()->create();
        // Direkt auf "bereit" gesetzt, um die Vollständigkeitsprüfung isoliert
        // von der Entwurfssperre (Befund 10) zu testen.
        $listing = Listing::factory()->create(['titel' => null, 'status' => ListingStatus::Bereit]);

        $response = $this->actingAs($user)->post(route('app.listings.publish', $listing), [
            'portale' => ['immoscout24'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Titel', session('error'));

        $this->assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    /**
     * Prüfbericht 2026-09-11, Befund 10: Ein Entwurf wurde durch einen
     * einzigen POST auf /veroeffentlichen angelegt, übertragen und
     * veröffentlicht, weil der Controller den Entwurf selbst auf "bereit"
     * hob. Portiert aus Poc07EntwurfPublishTest.php mit umgekehrter
     * Erwartung: der Aufruf wird abgelehnt, nichts wird übertragen oder
     * veröffentlicht, der bewusste Zwischenschritt "Als bereit markieren"
     * bleibt erforderlich.
     */
    public function test_ein_entwurf_kann_nicht_ueber_einen_einzigen_post_veroeffentlicht_werden(): void
    {
        $fake = new class implements PublishingService
        {
            public int $aufrufe = 0;

            public function isConfigured(): bool
            {
                return true;
            }

            public function portals(): array
            {
                return [];
            }

            public function transfer(Listing $listing, ?User $user = null): SyncResult
            {
                $this->aufrufe++;

                return SyncResult::failed('sollte nicht aufgerufen werden');
            }

            public function publish(Listing $listing, array $portalIds, ?User $user = null): PublishResult
            {
                $this->aufrufe++;

                return new PublishResult(true, 'sollte nicht aufgerufen werden');
            }

            public function withdraw(Listing $listing, array $portalIds, ?User $user = null): PublishResult
            {
                $this->aufrufe++;

                return new PublishResult(true, 'sollte nicht aufgerufen werden');
            }

            public function refreshStatus(Listing $listing): void
            {
                $this->aufrufe++;
            }
        };

        $this->app->instance(PublishingService::class, $fake);

        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create(['status' => ListingStatus::Entwurf]);

        $response = $this->actingAs($user)->post(route('app.listings.publish', $listing), [
            'portale' => ['immoscout24'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Bitte markieren Sie das Objekt zuerst als bereit.');
        $this->assertSame(ListingStatus::Entwurf, $listing->fresh()->status);
        $this->assertSame(0, $fake->aufrufe, 'PublishingService darf für einen Entwurf nie aufgerufen werden.');
    }

    public function test_die_veroeffentlichung_ohne_portalauswahl_schlaegt_fehl(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create();

        $response = $this->actingAs($user)->post(route('app.listings.publish', $listing), []);

        $response->assertSessionHasErrors('portale');
    }

    public function test_die_veroeffentlichung_eines_vollstaendigen_objekts_zeigt_die_nichtkonfiguriert_meldung_und_aendert_den_status_nicht(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create(['status' => ListingStatus::Bereit]);

        $response = $this->actingAs($user)->post(route('app.listings.publish', $listing), [
            'portale' => ['immoscout24'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', NullPublishingService::MELDUNG);

        $this->assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    public function test_ein_vollstaendiges_objekt_kann_auf_bereit_gesetzt_werden(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create();

        $response = $this->actingAs($user)->post(route('app.listings.status.bereit', $listing));

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    public function test_ein_unvollstaendiges_objekt_kann_nicht_auf_bereit_gesetzt_werden(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['titel' => null]);

        $response = $this->actingAs($user)->post(route('app.listings.status.bereit', $listing));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(ListingStatus::Entwurf, $listing->fresh()->status);
    }
}
