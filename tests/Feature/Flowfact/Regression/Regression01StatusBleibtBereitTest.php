<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\ListingPortalPublication;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;

/**
 * Prüfbericht 2026-09-11, Befund 1: Schlagen alle Portalanforderungen fehl,
 * bleibt das Objekt im bisherigen Status und kann archiviert werden. Ein
 * Objekt, dessen Publikationen sämtlich gescheitert, unbekannt oder
 * zurückgezogen sind, findet über das Rücklesen aus "veröffentlicht" zurück.
 */
final class Regression01StatusBleibtBereitTest extends FlowfactTestCase
{
    private const string PORTALS = '#^/portal-management-service/portals$#';

    private const string PUBLISH = '#^/portal-management-service/publish$#';

    private const string ESTATE_PORTALS = '#^/portal-management-service/estates/[^/]+/portals$#';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
    }

    private function uebertragenesListing(ListingStatus $status = ListingStatus::Bereit): Listing
    {
        $listing = $this->bereitesListing(['status' => $status]);
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);

        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id,
            'flowfact_entity_id' => 'ent-1',
            'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen,
            'uebertragener_inhalt_hash' => app(ListingContentHasher::class)->hash($listing),
            'letzte_uebertragung_at' => now(),
        ]);

        return $listing;
    }

    public function test_publish_mit_http_500_laesst_das_listing_bereit_und_archivieren_bleibt_moeglich(): void
    {
        $listing = $this->uebertragenesListing();
        $admin = User::factory()->admin()->create();

        $fake = $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, fn () => Http::response(['message' => 'Portal down'], 500))
            ->on('GET', self::ESTATE_PORTALS, fn () => Http::response([]))
            ->install();

        $antwort = $this->actingAs($admin)->post(route('app.listings.publish', $listing), ['portale' => ['portal-is24']]);
        $antwort->assertRedirect();
        $antwort->assertSessionHas('error');
        self::assertStringContainsString(FlowfactPublishingService::MELDUNG_KEIN_PORTAL_ANGEFORDERT, (string) session('error'));

        $listing->refresh();
        $publication = ListingPortalPublication::query()->where('listing_id', $listing->id)->first();

        self::assertSame(1, $fake->count('POST', self::PUBLISH));
        self::assertSame(ListingStatus::Bereit, $listing->status, 'Ohne erfolgreiche Anforderung bleibt der Status unverändert.');
        self::assertSame(PortalStatus::Fehler, $publication->status);

        // Archivieren ist aus "bereit" zulässig, das Objekt ist nicht blockiert.
        $this->actingAs($admin)->post(route('app.listings.archive', $listing))->assertSessionHas('status');
        self::assertSame(ListingStatus::Archiviert, $listing->fresh()->status);
    }

    public function test_publish_mit_errors_in_der_antwort_laesst_das_listing_bereit(): void
    {
        $listing = $this->uebertragenesListing();
        $admin = User::factory()->admin()->create();

        $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, self::publishResponse('ent-1', fehler: ['portal-is24' => 'Pflichtfeld fehlt']))
            ->on('GET', self::ESTATE_PORTALS, fn () => Http::response([]))
            ->install();

        $this->actingAs($admin)->post(route('app.listings.publish', $listing), ['portale' => ['portal-is24']])->assertSessionHas('error');

        self::assertSame(ListingStatus::Bereit, $listing->fresh()->status);
        self::assertSame(PortalStatus::Fehler, ListingPortalPublication::query()->first()->status);
        self::assertStringContainsString('Pflichtfeld fehlt', (string) ListingPortalPublication::query()->first()->letzter_fehler);
    }

    public function test_teilerfolg_zaehlt_nur_das_erfolgreich_angeforderte_portal(): void
    {
        $listing = $this->uebertragenesListing();

        $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, function ($request) {
                $portalId = (string) $request->data()['portalId'];

                return $portalId === 'portal-is24'
                    ? Http::response(['message' => 'Portal down'], 500)
                    : Http::response('', 200);
            })
            ->install();

        $ergebnis = app(FlowfactPublishingService::class)->publish($listing, ['portal-is24', 'portal-openimmo']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertStringContainsString('teilweise fehlgeschlagen', $ergebnis->meldung);
        self::assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status);
        self::assertSame(PortalStatus::Fehler, ListingPortalPublication::query()->where('portal_id', 'portal-is24')->first()->status);
        self::assertSame(PortalStatus::Angefordert, ListingPortalPublication::query()->where('portal_id', 'portal-openimmo')->first()->status);
    }

    public function test_publikation_im_status_unbekannt_kann_zurueckgezogen_werden(): void
    {
        $listing = $this->uebertragenesListing(ListingStatus::Veroeffentlicht);
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-is24',
            'status' => PortalStatus::Unbekannt,
            'angefordert_at' => now()->subHours(2),
        ]);

        $fake = $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, fn () => Http::response('', 200))
            ->on('GET', self::ESTATE_PORTALS, fn () => Http::response([]))
            ->install();

        $service = app(FlowfactPublishingService::class);
        $ergebnis = $service->withdraw($listing, ['portal-is24']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('POST', self::PUBLISH), 'OFFLINE wird auch für "unbekannt" gesendet.');
        self::assertSame('OFFLINE', $fake->requests('POST', self::PUBLISH)[0]->data()['entries'][0]['targetStatus']);

        // Rücklesen ohne Eintrag: zurückgezogen; nie bestätigt aktiv, daher zurück nach bereit.
        $service->refreshStatus($listing);
        self::assertSame(PortalStatus::Zurueckgezogen, ListingPortalPublication::query()->first()->status);
        self::assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    public function test_reine_fehlerpublikation_wird_nach_rueckzug_lokal_zurueckgesetzt(): void
    {
        $listing = $this->uebertragenesListing(ListingStatus::Veroeffentlicht);
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-is24',
            'status' => PortalStatus::Fehler,
            'angefordert_at' => now()->subHour(),
            'letzter_fehler' => 'Portal down',
        ]);

        $fake = $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, fn () => Http::response('', 200))
            ->on('GET', self::ESTATE_PORTALS, fn () => Http::response([]))
            ->install();

        $ergebnis = app(FlowfactPublishingService::class)->withdraw($listing, ['portal-is24']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('POST', self::PUBLISH));
        self::assertSame(1, $fake->count('GET', self::ESTATE_PORTALS), 'Nach dem Rückzug einer Fehlerpublikation wird nachgelesen.');

        $publication = ListingPortalPublication::query()->first();
        self::assertSame(PortalStatus::NichtVeroeffentlicht, $publication->status);
        self::assertNull($publication->letzter_fehler);
        self::assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    public function test_portal_status_fuehrt_veroeffentlichtes_objekt_ohne_offene_publikation_nach_bereit(): void
    {
        // Altbestand: Objekt steht auf veröffentlicht, obwohl nur eine Fehlerpublikation vorliegt.
        $listing = $this->uebertragenesListing(ListingStatus::Veroeffentlicht);
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-is24',
            'status' => PortalStatus::Fehler,
            'angefordert_at' => now()->subHour(),
            'letzter_fehler' => 'Portal down',
        ]);
        Http::fake([self::BASE.'/portal-management-service/estates/*' => Http::response([])]);

        $this->artisan('flow:portal-status')->assertExitCode(0);

        self::assertSame(PortalStatus::Fehler, ListingPortalPublication::query()->first()->status, 'Der Portalstatus bleibt korrekt.');
        self::assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    public function test_nach_bestaetigter_aktivitaet_zurueckgezogenes_portal_fuehrt_nach_zurueckgezogen_nicht_nach_bereit(): void
    {
        $listing = $this->uebertragenesListing(ListingStatus::Veroeffentlicht);
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-is24',
            'status' => PortalStatus::Aktiv,
            'angefordert_at' => now()->subHours(2),
            'bestaetigt_at' => now()->subHour(),
            'zurueckgezogen_at' => now()->subMinutes(5),
        ]);
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-openimmo',
            'status' => PortalStatus::Fehler,
            'angefordert_at' => now()->subHours(2),
        ]);
        Http::fake([self::BASE.'/portal-management-service/estates/*' => Http::response([])]);

        app(FlowfactPublishingService::class)->refreshStatus($listing);

        self::assertSame(ListingStatus::Zurueckgezogen, $listing->fresh()->status);
    }
}
