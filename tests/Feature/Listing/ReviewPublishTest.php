<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\EnergieausweisStatus;
use App\Enums\ListingStatus;
use App\Enums\PruefEbene;
use App\Flowfact\Sync\NullPublishingService;
use App\Flowfact\Sync\PortalInfo;
use App\Flowfact\Sync\PublishingService;
use App\Flowfact\Sync\PublishResult;
use App\Flowfact\Sync\SyncResult;
use App\Models\Listing;
use App\Models\ListingRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prüfen und veröffentlichen (Masterprompt Abschnitt 17 bis 20,
 * Masterprompt-Abgleich B.4, B.6). Enthält, portiert aus der entfernten
 * WizardStep8PublishTest, die Prüfungen der serverseitigen Blockade eines
 * unvollständigen Objekts und des Entwurfsschutzes (Prüfbericht 2026-09-11,
 * Befund 10).
 */
final class ReviewPublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_die_pruefseite_zeigt_die_fehlenden_felder_eines_unvollstaendigen_objekts(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['titel' => null]);

        $response = $this->actingAs($user)->get(route('app.listings.review', $listing));

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

        $response = $this->actingAs($user)->get(route('app.listings.review', $listing));

        $response->assertOk();
        $response->assertDontSee('INTERN-MARKER-NAME');
        $response->assertDontSee('INTERN-MARKER-KONTAKT');
        $response->assertDontSee('INTERN-MARKER-REFERENZ');
        $response->assertDontSee('INTERN-MARKER-NOTIZ');
        $response->assertDontSee('INTERN-MARKER-SCHLUESSEL');
        $response->assertDontSee('INTERN-MARKER-BESICHTIGUNG');
        $response->assertDontSee('INTERN-MARKER-KALKULATION');
    }

    public function test_die_pruefebenen_gruppieren_die_befunde(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['titel' => null]);

        $response = $this->actingAs($user)->get(route('app.listings.review', $listing));

        $response->assertOk();
        foreach (PruefEbene::cases() as $ebene) {
            $response->assertSee($ebene->label());
        }
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
        $this->assertSame(0, ListingRelease::query()->count(), 'Ohne vollständige Prüfung darf keine Freigabeversion entstehen.');
    }

    /**
     * Prüfbericht 2026-09-11, Befund 10: Ein Entwurf wurde durch einen
     * einzigen POST auf /veroeffentlichen angelegt, übertragen und
     * veröffentlicht, weil der Controller den Entwurf selbst auf "bereit"
     * hob. Der bewusste Zwischenschritt "Als bereit markieren" bleibt
     * erforderlich.
     */
    public function test_ein_entwurf_kann_nicht_ueber_einen_einzigen_post_veroeffentlicht_werden(): void
    {
        $fake = $this->fakePublishingService();
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

    public function test_die_veroeffentlichung_erzeugt_eine_freigabeversion_bevor_der_connector_aufgerufen_wird(): void
    {
        $fake = $this->fakePublishingService();
        $this->app->instance(PublishingService::class, $fake);

        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create(['status' => ListingStatus::Bereit]);

        $response = $this->actingAs($user)->post(route('app.listings.publish', $listing), [
            'portale' => ['immoscout24'],
        ]);

        $response->assertRedirect();
        $this->assertTrue($fake->releaseVorhandenBeimAufruf, 'Die Freigabeversion muss vor dem Aufruf von PublishingService::publish existieren.');
        $this->assertSame(1, ListingRelease::query()->where('listing_id', $listing->id)->count());
        $this->assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status);
    }

    public function test_die_veroeffentlichung_mit_alle_portale_waehlt_alle_verfuegbaren_portale(): void
    {
        $fake = $this->fakePublishingService();
        $this->app->instance(PublishingService::class, $fake);

        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create(['status' => ListingStatus::Bereit]);

        $response = $this->actingAs($user)->post(route('app.listings.publish', $listing), [
            'alle_portale' => '1',
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors('portale');
        self::assertEqualsCanonicalizing(['immoscout24', 'immowelt'], $fake->angefragtePortalIds);
    }

    public function test_leser_kann_nicht_veroeffentlichen(): void
    {
        $leser = User::factory()->leser()->create();
        $listing = Listing::factory()->vollstaendig()->create(['status' => ListingStatus::Bereit]);

        $response = $this->actingAs($leser)->post(route('app.listings.publish', $listing), [
            'portale' => ['immoscout24'],
        ]);

        $response->assertForbidden();
    }

    public function test_mitarbeiter_ohne_veroeffentlichungsrecht_kann_nicht_veroeffentlichen(): void
    {
        $mitarbeiter = User::factory()->ohneVeroeffentlichungsrecht()->create();
        $listing = Listing::factory()->vollstaendig()->create(['status' => ListingStatus::Bereit]);

        $response = $this->actingAs($mitarbeiter)->post(route('app.listings.publish', $listing), [
            'portale' => ['immoscout24'],
        ]);

        $response->assertForbidden();
    }

    public function test_admin_kann_die_ausnahme_vom_energieausweis_bestaetigen(): void
    {
        $admin = User::factory()->admin()->create();
        $listing = Listing::factory()->vollstaendig()->create();
        $listing->energy()->update(['status' => EnergieausweisStatus::AusnahmeZuPruefen]);

        $response = $this->actingAs($admin)->post(route('app.listings.energy-exception.confirm', $listing), [
            'ausnahme_begruendung' => 'Baudenkmal, Ausweispflicht entfällt.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $listing->refresh();
        $this->assertTrue($listing->energy->ausnahmeBestaetigt());
        $this->assertSame($admin->id, $listing->energy->ausnahme_bestaetigt_von_user_id);
    }

    public function test_mitarbeiter_kann_die_energieausweisausnahme_nicht_bestaetigen(): void
    {
        $mitarbeiter = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create();
        $listing->energy()->update(['status' => EnergieausweisStatus::AusnahmeZuPruefen]);

        $response = $this->actingAs($mitarbeiter)->post(route('app.listings.energy-exception.confirm', $listing), [
            'ausnahme_begruendung' => 'Baudenkmal.',
        ]);

        $response->assertForbidden();
    }

    private function fakePublishingService(): PublishingService
    {
        return new class implements PublishingService
        {
            public int $aufrufe = 0;

            /** @var list<string> */
            public array $angefragtePortalIds = [];

            public bool $releaseVorhandenBeimAufruf = false;

            public function isConfigured(): bool
            {
                return true;
            }

            /**
             * @return list<PortalInfo>
             */
            public function portals(): array
            {
                return [
                    new PortalInfo('immoscout24', 'ImmoScout24', 'immoscout24', true),
                    new PortalInfo('immowelt', 'Immowelt', 'immowelt', true),
                ];
            }

            public function transfer(Listing $listing, ?User $user = null): SyncResult
            {
                $this->aufrufe++;

                return SyncResult::failed('sollte nicht aufgerufen werden');
            }

            public function publish(Listing $listing, array $portalIds, ?User $user = null): PublishResult
            {
                $this->aufrufe++;
                $this->angefragtePortalIds = $portalIds;
                $this->releaseVorhandenBeimAufruf = ListingRelease::query()->where('listing_id', $listing->id)->exists();

                // Ein echter Erfolg meldet die Zahl der angeforderten Portale (Fall B hätte 0).
                return new PublishResult(true, 'Die Veröffentlichung wurde angefordert.', angefordert: max(1, count($portalIds)));
            }

            public function withdraw(Listing $listing, array $portalIds, ?User $user = null): PublishResult
            {
                $this->aufrufe++;

                return new PublishResult(true, 'sollte in diesem Test nicht aufgerufen werden');
            }

            public function refreshStatus(Listing $listing): void
            {
                $this->aufrufe++;
            }
        };
    }
}
