<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Flowfact\Sync\Jobs\TransferListingJob;
use App\Flowfact\Sync\PublishingService;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\ListingPortalPublication;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\Support\FakeFlowfact;

final class FlowfactPublishingServiceTest extends FlowfactTestCase
{
    private const string PORTALS = '#^/portal-management-service/portals$#';

    private const string PUBLISH = '#^/portal-management-service/publish$#';

    private const string ESTATE_PORTALS = '#^/portal-management-service/estates/[^/]+/portals$#';

    private const string GET_ENTITY = '#^/entity-service/schemas/[^/]+/entities/[^/]+$#';

    private const string ITEMS = '#^/multimedia-service/items/entities/[^/]+$#';

    private const string ASSIGN = '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#';

    protected function setUp(): void
    {
        parent::setUp();

        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images']);
    }

    private function service(): FlowfactPublishingService
    {
        return app(FlowfactPublishingService::class);
    }

    /**
     * Vollständiges, bereits aktuell übertragenes Objekt ohne Bilduploads.
     */
    private function uebertragenesListing(ListingStatus $status = ListingStatus::Bereit): Listing
    {
        $listing = $this->bereitesListing(['status' => $status]);
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'titel' => null, 'flowfact_titel' => null]);
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

    private function fakePortale(mixed $publishAntwort = null, array $online = []): FakeFlowfact
    {
        return $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, $publishAntwort ?? fn () => Http::response('', 200))
            ->on('GET', self::ESTATE_PORTALS, fn () => Http::response(self::estatePortalsResponse($online)))
            ->install();
    }

    public function test_portale_werden_mit_authenticated_flag_geliefert(): void
    {
        $this->fakePortale();

        $portale = $this->service()->portals();

        self::assertCount(3, $portale);
        self::assertSame('portal-is24', $portale[0]->id);
        self::assertSame('ImmoScout24', $portale[0]->name);
        self::assertSame('IS24', $portale[0]->type);
        self::assertTrue($portale[0]->authenticated);
        self::assertFalse($portale[2]->authenticated);
    }

    public function test_entwurf_kann_nicht_veroeffentlicht_werden(): void
    {
        Http::fake();
        $listing = Listing::factory()->miete()->vollstaendig()->create(['status' => ListingStatus::Entwurf]);

        $ergebnis = $this->service()->publish($listing, ['portal-is24']);

        self::assertFalse($ergebnis->ok);
        self::assertStringContainsString('Entwürfe sind ausgeschlossen', $ergebnis->meldung);
        Http::assertNothingSent();
        self::assertSame(0, ListingPortalPublication::query()->count());
    }

    public function test_unvollstaendiges_objekt_wird_abgelehnt(): void
    {
        Http::fake();
        $listing = Listing::factory()->miete()->create(['status' => ListingStatus::Bereit]);

        $ergebnis = $this->service()->publish($listing, ['portal-is24']);

        self::assertFalse($ergebnis->ok);
        self::assertStringContainsString('nicht vollständig', $ergebnis->meldung);
        Http::assertNothingSent();
    }

    public function test_leerer_koerper_bleibt_angefordert_und_setzt_listing_auf_veroeffentlicht(): void
    {
        $listing = $this->uebertragenesListing();
        $fake = $this->fakePortale();
        $user = User::factory()->create();

        $ergebnis = $this->service()->publish($listing, ['portal-is24'], $user);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('POST', self::PUBLISH));
        self::assertSame(0, $fake->count('GET', self::ESTATE_PORTALS), 'Ohne successFullyTransfered wird nicht sofort nachgelesen.');

        $request = $fake->requests('POST', self::PUBLISH)[0]->data();
        self::assertSame('portal-is24', $request['portalId']);
        self::assertSame('IS24', $request['portalType']);
        self::assertSame('MANUAL', $request['publishType']);
        self::assertSame([['entityId' => 'ent-1', 'schema' => self::SCHEMA_MIETE, 'targetStatus' => 'ONLINE', 'showAddress' => true]], $request['entries']);

        $publication = ListingPortalPublication::query()->first();
        self::assertSame(PortalStatus::Angefordert, $publication->status);
        self::assertSame('ImmoScout24', $publication->portal_name);
        self::assertNotNull($publication->angefordert_at);
        self::assertNull($publication->bestaetigt_at);
        self::assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status);
    }

    public function test_errors_in_der_antwort_setzen_fehler_mit_uebersetzter_meldung(): void
    {
        $listing = $this->uebertragenesListing();
        $this->fakePortale(self::publishResponse('ent-1', fehler: ['portal-is24' => 'Bitte geben Sie einen Energieausweis an.']));

        $ergebnis = $this->service()->publish($listing, ['portal-is24']);

        self::assertFalse($ergebnis->ok);
        self::assertStringContainsString('Bitte geben Sie einen Energieausweis an.', $ergebnis->meldung);

        $publication = ListingPortalPublication::query()->first();
        self::assertSame(PortalStatus::Fehler, $publication->status);
        self::assertSame('Bitte geben Sie einen Energieausweis an.', $publication->letzter_fehler);
    }

    public function test_success_fully_transfered_setzt_aktiv_erst_nach_ruecklesen(): void
    {
        $listing = $this->uebertragenesListing();
        $fake = $this->fakePortale(self::publishResponse('ent-1', transferiert: ['portal-is24']), online: ['portal-is24']);

        $ergebnis = $this->service()->publish($listing, ['portal-is24']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('GET', self::ESTATE_PORTALS));

        $publication = ListingPortalPublication::query()->first();
        self::assertSame(PortalStatus::Aktiv, $publication->status);
        self::assertNotNull($publication->bestaetigt_at);
        self::assertNotNull($publication->letzte_pruefung_at);
    }

    public function test_kein_aktiv_ohne_ruecklesen_mit_online_since(): void
    {
        $listing = $this->uebertragenesListing();
        // successFullyTransfered, aber das Rücklesen zeigt den Eintrag noch ohne onlineSince.
        $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, self::publishResponse('ent-1', transferiert: ['portal-is24']))
            ->on('GET', self::ESTATE_PORTALS, self::estatePortalsResponse([], ['portal-is24']))
            ->install();

        $ergebnis = $this->service()->publish($listing, ['portal-is24']);

        self::assertTrue($ergebnis->ok);
        self::assertSame(PortalStatus::Angefordert, ListingPortalPublication::query()->first()->status);
    }

    public function test_successfully_scheduled_bleibt_angefordert(): void
    {
        $listing = $this->uebertragenesListing();
        $this->fakePortale(self::publishResponse('ent-1', geplant: ['portal-is24']));

        $this->service()->publish($listing, ['portal-is24']);

        self::assertSame(PortalStatus::Angefordert, ListingPortalPublication::query()->first()->status);
    }

    public function test_nicht_authentifiziertes_oder_unbekanntes_portal_wird_abgewiesen(): void
    {
        $listing = $this->uebertragenesListing();
        $fake = $this->fakePortale();

        $ergebnis = $this->service()->publish($listing, ['portal-inaktiv', 'portal-unbekannt']);

        self::assertFalse($ergebnis->ok);
        self::assertSame(0, $fake->count('POST', self::PUBLISH));
        self::assertSame(0, ListingPortalPublication::query()->count());
        self::assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    public function test_veraltete_uebertragung_wird_vor_der_veroeffentlichung_aktualisiert(): void
    {
        $listing = $this->uebertragenesListing();
        $listing->flowfactLink()->update(['uebertragener_inhalt_hash' => 'veraltet']);

        $fake = $this->fake()
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->on('PATCH', self::GET_ENTITY, self::entityResponse('ent-1'))
            // Bei geändertem Hash wird die Bildreihenfolge neu gesetzt (Befund 4).
            ->on('GET', self::ITEMS, [self::multimediaItem(101)])
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, fn () => Http::response('', 200))
            ->install();

        $ergebnis = $this->service()->publish($listing, ['portal-is24']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('PATCH', self::GET_ENTITY));
        self::assertSame(1, $fake->count('POST', self::PUBLISH));
        self::assertSame(SyncStatus::Uebertragen, $listing->flowfactLink()->first()->sync_status);
    }

    public function test_fehlgeschlagene_uebertragung_verhindert_den_publish(): void
    {
        $listing = $this->uebertragenesListing();
        $listing->flowfactLink()->update(['uebertragener_inhalt_hash' => 'veraltet']);

        $fake = $this->fake()
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->on('PATCH', self::GET_ENTITY, ['message' => 'kaputt'], 500)
            ->on('POST', self::PUBLISH, fn () => Http::response('', 200))
            ->install();

        $ergebnis = $this->service()->publish($listing, ['portal-is24']);

        self::assertFalse($ergebnis->ok);
        self::assertStringContainsString('Übertragung an FLOWFACT ist fehlgeschlagen', $ergebnis->meldung);
        self::assertSame(0, $fake->count('POST', self::PUBLISH));
    }

    public function test_zurueckgezogenes_objekt_wird_ueber_bereit_wieder_veroeffentlicht(): void
    {
        $listing = $this->uebertragenesListing(ListingStatus::Zurueckgezogen);
        $this->fakePortale();

        $ergebnis = $this->service()->publish($listing, ['portal-openimmo']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status);
    }

    public function test_withdraw_sendet_offline_und_setzt_zurueckgezogen_erst_nach_ruecklesen(): void
    {
        $listing = $this->uebertragenesListing(ListingStatus::Veroeffentlicht);
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-is24',
            'portal_name' => 'ImmoScout24',
            'status' => PortalStatus::Aktiv,
            'angefordert_at' => now()->subHour(),
            'bestaetigt_at' => now()->subMinutes(50),
        ]);

        // Erster Aufruf: leerer Körper, das Portal meldet noch online.
        $online = ['portal-is24'];
        $fake = $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, fn () => Http::response('', 200))
            ->on('GET', self::ESTATE_PORTALS, function () use (&$online) {
                return Http::response(self::estatePortalsResponse($online));
            })
            ->install();

        $ergebnis = $this->service()->withdraw($listing, ['portal-is24']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        $request = $fake->requests('POST', self::PUBLISH)[0]->data();
        self::assertSame('OFFLINE', $request['entries'][0]['targetStatus']);

        $publication = ListingPortalPublication::query()->first();
        self::assertSame(PortalStatus::Aktiv, $publication->status, 'Bis zum Rücklesen bleibt der alte Status.');
        self::assertSame(FlowfactPublishingService::HINWEIS_RUECKZUG, $publication->letzter_fehler);
        self::assertNotNull($publication->zurueckgezogen_at);
        self::assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status);

        // Rücklesen ohne Eintrag: zurückgezogen, alle Portale weg, Objekt zurückgezogen.
        $online = [];
        $this->service()->refreshStatus($listing);

        $publication->refresh();
        self::assertSame(PortalStatus::Zurueckgezogen, $publication->status);
        self::assertNull($publication->letzter_fehler);
        self::assertSame(ListingStatus::Zurueckgezogen, $listing->fresh()->status);
    }

    public function test_withdraw_mit_success_fully_transfered_liest_sofort_nach(): void
    {
        $listing = $this->uebertragenesListing(ListingStatus::Veroeffentlicht);
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-is24',
            'status' => PortalStatus::Aktiv,
            'angefordert_at' => now()->subHour(),
            // Ein aktives Portal trägt immer bestaetigt_at; nur ein nach bestätigter
            // Aktivität zurückgezogenes Portal führt nach zurueckgezogen (Befund 1).
            'bestaetigt_at' => now()->subMinutes(50),
        ]);
        $fake = $this->fakePortale(self::publishResponse('ent-1', transferiert: ['portal-is24']), online: []);

        $ergebnis = $this->service()->withdraw($listing, ['portal-is24']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('GET', self::ESTATE_PORTALS));
        self::assertSame(PortalStatus::Zurueckgezogen, ListingPortalPublication::query()->first()->status);
        self::assertSame(ListingStatus::Zurueckgezogen, $listing->fresh()->status);
    }

    public function test_refresh_status_markiert_alte_anforderung_als_unbekannt(): void
    {
        Carbon::setTestNow('2026-09-11 12:00:00');
        $listing = $this->uebertragenesListing(ListingStatus::Veroeffentlicht);
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-is24',
            'status' => PortalStatus::Angefordert,
            'angefordert_at' => Carbon::now()->subMinutes(31),
        ]);
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-openimmo',
            'status' => PortalStatus::Angefordert,
            'angefordert_at' => Carbon::now()->subMinutes(5),
        ]);
        Http::fake([self::BASE.'/portal-management-service/estates/*' => Http::response([])]);

        $this->service()->refreshStatus($listing);

        $alt = ListingPortalPublication::query()->where('portal_id', 'portal-is24')->first();
        $neu = ListingPortalPublication::query()->where('portal_id', 'portal-openimmo')->first();
        self::assertSame(PortalStatus::Unbekannt, $alt->status);
        self::assertSame(FlowfactPublishingService::HINWEIS_UNBEKANNT, $alt->letzter_fehler);
        self::assertSame(PortalStatus::Angefordert, $neu->status);
        self::assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status);

        Carbon::setTestNow();
    }

    /**
     * Prüfbericht 2026-09-11, Befund 15: Erreicht der synchrone Weg beim
     * Bildupload das Zeitlimit, wird nicht veröffentlicht.
     */
    public function test_veroeffentlichung_bricht_ab_wenn_die_bilduebertragung_noch_offen_ist(): void
    {
        Queue::fake();
        $listing = $this->bereitesListing();
        Storage::disk('media')->put($listing->media()->first()->pfad, $this->beispielbild());

        $fake = $this->fake()
            ->on('POST', '#^/search-service/schemas/[^/]+$#', self::searchResponse([]))
            ->on('POST', '#^/entity-service/schemas/[^/]+$#', self::entityResponse('ent-1'))
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, fn () => Http::response('', 200))
            ->install();

        // Zeitlimit 0 Sekunden: der erste Bildupload liegt bereits hinter der Frist.
        $service = app()->makeWith(FlowfactPublishingService::class, ['syncZeitlimitSekunden' => 0]);

        $ergebnis = $service->publish($listing, ['portal-is24']);

        self::assertFalse($ergebnis->ok);
        self::assertSame(FlowfactPublishingService::MELDUNG_BILDER_OFFEN, $ergebnis->meldung);
        self::assertSame(0, $fake->count('POST', self::PUBLISH), 'Kein Inserat mit unvollständigem Bildsatz.');
        self::assertSame(1, $fake->count('POST', '#^/entity-service/schemas/[^/]+$#'), 'Die Entität wurde angelegt.');
        self::assertSame(SyncStatus::GeaendertSeitUebertragung, $listing->flowfactLink()->first()->sync_status);
        self::assertSame(ListingStatus::Bereit, $listing->fresh()->status);
        self::assertSame(0, ListingPortalPublication::query()->count());
        Queue::assertPushed(TransferListingJob::class);
    }

    public function test_publish_result_traegt_auth_ausnahme(): void
    {
        $listing = $this->uebertragenesListing();
        $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, ['message' => 'nope'], 403)
            ->install();

        $ergebnis = $this->service()->publish($listing, ['portal-is24']);

        self::assertFalse($ergebnis->ok);
        self::assertInstanceOf(AuthenticationException::class, $ergebnis->ausnahme);
        self::assertSame(PortalStatus::Fehler, ListingPortalPublication::query()->first()->status);
        self::assertStringNotContainsString(self::TOKEN, $ergebnis->meldung);
    }

    public function test_interface_ist_bei_token_auf_die_echte_umsetzung_gebunden(): void
    {
        self::assertInstanceOf(FlowfactPublishingService::class, app(PublishingService::class));
        self::assertTrue(app(PublishingService::class)->isConfigured());
    }
}
