<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Flowfact\Sync\Jobs\TransferListingJob;
use App\Flowfact\Sync\ListingSyncService;
use App\Flowfact\Sync\PortalStatusTransition;
use App\Flowfact\Sync\PublishingService;
use App\Flowfact\Sync\ReleaseGuard;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\ListingPortalPublication;
use App\Models\ListingRelease;
use App\Models\TransferLog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;
use Tests\Feature\Flowfact\Support\FakeFlowfact;

/**
 * Prüfbericht 2026-09-12, Befund 2: Der ReleaseGuard leitet "Deaktivierung
 * nach der Freigabe" aus Nachweisen (listing_portal_status_logs,
 * zurueckgezogen_at) ab, nie aus updated_at. Ein Rücklesen ohne Statuswechsel
 * berührt keine Zeile regulär; eine alte, bestätigte Deaktivierung darf eine
 * jüngere Freigabe nicht unterdrücken.
 */
final class Regression15ReleaseGuardBeruehrenTest extends FlowfactTestCase
{
    private const string PORTALS = '#^/portal-management-service/portals$#';

    private const string PUBLISH = '#^/portal-management-service/publish$#';

    private const string ESTATE_PORTALS = '#^/portal-management-service/estates/[^/]+/portals$#';

    private const string GET_ENTITY = '#^/entity-service/schemas/[^/]+/entities/[^/]+$#';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images']);
        Carbon::setTestNow('2026-09-12 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Vorgeschichte: IS24 aktiv seit 30 Tagen, Immowelt vor 10 Tagen
     * deaktiviert und bestätigt. Um 10:00 neue Freigabe für beide Portale,
     * Übertragung bereits abgeschlossen (Fortsetzung nach Bildupload).
     *
     * @return array{0: Listing, 1: ListingRelease}
     */
    private function objektMitAlterDeaktivierung(): array
    {
        $listing = $this->bereitesListing();
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild', 'titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);

        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id, 'portal_id' => 'portal-is24', 'portal_name' => 'ImmoScout24',
            'status' => PortalStatus::Aktiv, 'angefordert_at' => now()->subDays(30), 'bestaetigt_at' => now()->subDays(30),
            'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30),
        ]);
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id, 'portal_id' => 'portal-openimmo', 'portal_name' => 'Immowelt',
            'status' => PortalStatus::DeaktivierungBestaetigt, 'angefordert_at' => now()->subDays(30), 'bestaetigt_at' => now()->subDays(30),
            'zurueckgezogen_at' => now()->subDays(10), 'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(10),
        ]);

        $release = $this->freigeben($listing, ['portal-openimmo', 'portal-is24']);
        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id, 'flowfact_entity_id' => 'ent-1', 'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen, 'uebertragener_inhalt_hash' => $release->inhalt_hash, 'release_id' => $release->id,
        ]);

        return [$listing, $release];
    }

    private function fakePortale(): FakeFlowfact
    {
        return $this->fake()
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, fn () => Http::response('', 200))
            ->on('GET', self::ESTATE_PORTALS, fn () => Http::response(self::estatePortalsResponse(['portal-is24'])))
            ->install();
    }

    /**
     * @return list<string> Portal-IDs der POST /publish ONLINE Anforderungen
     */
    private function onlinePortale(FakeFlowfact $fake): array
    {
        $portale = [];

        foreach ($fake->requests('POST', self::PUBLISH) as $request) {
            if (($request->data()['entries'][0]['targetStatus'] ?? null) === 'ONLINE') {
                $portale[] = (string) $request->data()['portalId'];
            }
        }

        sort($portale);

        return $portale;
    }

    public function test_ruecklesen_eines_anderen_portals_blockiert_die_neue_veroeffentlichung_nicht(): void
    {
        [$listing, $release] = $this->objektMitAlterDeaktivierung();
        $guard = app(ReleaseGuard::class);
        self::assertFalse($guard->deaktivierungNachFreigabe($listing, $release), 'Direkt nach der Freigabe liegt keine Deaktivierung vor.');

        // Der Scheduler (flow:portal-status) liest wegen IS24 nach; FLOWFACT meldet IS24 weiterhin online.
        Carbon::setTestNow('2026-09-12 10:05:00');
        $this->fakePortale();
        app(PublishingService::class)->refreshStatus($listing->fresh());

        $immowelt = ListingPortalPublication::query()->where('portal_id', 'portal-openimmo')->firstOrFail();
        $is24 = ListingPortalPublication::query()->where('portal_id', 'portal-is24')->firstOrFail();
        self::assertSame(PortalStatus::DeaktivierungBestaetigt, $immowelt->status);
        self::assertTrue($immowelt->updated_at->equalTo(Carbon::parse('2026-09-02 10:00:00')), 'Ohne Statuswechsel bleibt updated_at unberührt.');
        self::assertTrue($is24->updated_at->equalTo(Carbon::parse('2026-08-13 10:00:00')), 'Auch die aktive Zeile wird nicht regulär gespeichert.');
        self::assertTrue($immowelt->letzte_pruefung_at->equalTo(Carbon::now()), 'Der Prüfzeitpunkt für flow:portal-status wird trotzdem fortgeschrieben.');

        self::assertFalse($guard->deaktivierungNachFreigabe($listing, $release), 'Die alte Deaktivierung gilt nicht als jünger als die Freigabe.');

        // Der Fortsetzungsjob fordert die Veröffentlichung für beide Portale der Freigabe an.
        Carbon::setTestNow('2026-09-12 10:10:00');
        $fake = $this->fakePortale();
        (new TransferListingJob($listing->id, null, false, $release->id, true))->handle(app(ListingSyncService::class), $guard);

        self::assertSame(['portal-is24', 'portal-openimmo'], $this->onlinePortale($fake), 'POST /publish ONLINE für das früher deaktivierte Portal A und für Portal B.');
        self::assertSame(0, TransferLog::query()->where('listing_id', $listing->id)->where('zusammenfassung', ReleaseGuard::MELDUNG_DEAKTIVIERUNG)->count());
        self::assertSame(PortalStatus::Angefordert, $immowelt->fresh()->status);
        self::assertSame($release->id, $immowelt->fresh()->release_id);
        self::assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status);
    }

    public function test_updated_at_allein_ist_kein_nachweis_einer_deaktivierung(): void
    {
        [$listing, $release] = $this->objektMitAlterDeaktivierung();

        // Eine fremde Berührung der Zeile (z. B. Datenpflege) verschiebt updated_at hinter die Freigabe.
        Carbon::setTestNow('2026-09-12 10:05:00');
        ListingPortalPublication::query()->where('portal_id', 'portal-openimmo')->update(['updated_at' => Carbon::now()]);

        self::assertFalse(app(ReleaseGuard::class)->deaktivierungNachFreigabe($listing, $release));
    }

    public function test_nachweis_einer_anforderung_nach_der_freigabe_blockiert(): void
    {
        [$listing, $release] = $this->objektMitAlterDeaktivierung();
        $is24 = ListingPortalPublication::query()->where('portal_id', 'portal-is24')->firstOrFail();

        Carbon::setTestNow('2026-09-12 10:05:00');
        $is24->zurueckgezogen_at = null; // nur der Nachweis zählt, nicht der Zeitstempel
        PortalStatusTransition::apply($is24, PortalStatus::DeaktivierungAngefordert, PortalStatusTransition::QUELLE_OFFLINE_ANGEFORDERT, $release);

        self::assertTrue(app(ReleaseGuard::class)->deaktivierungNachFreigabe($listing, $release));
    }

    public function test_zurueckgezogen_at_nach_der_freigabe_blockiert(): void
    {
        [$listing, $release] = $this->objektMitAlterDeaktivierung();

        Carbon::setTestNow('2026-09-12 10:05:00');
        ListingPortalPublication::query()->where('portal_id', 'portal-is24')->update(['zurueckgezogen_at' => Carbon::now()]);

        self::assertTrue(app(ReleaseGuard::class)->deaktivierungNachFreigabe($listing, $release));
    }

    /**
     * Die Bestätigung einer Anforderung, die vor der Freigabe lag, ist kein
     * neuer Rückzug: Der Benutzer hat nach dem Rückzug erneut freigegeben,
     * diese jüngere Absicht bleibt wirksam.
     */
    public function test_bestaetigung_einer_anforderung_von_vor_der_freigabe_blockiert_nicht(): void
    {
        $listing = $this->bereitesListing(['status' => ListingStatus::Veroeffentlicht]);
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild', 'titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        ListingFlowfactLink::factory()->create(['listing_id' => $listing->id, 'flowfact_entity_id' => 'ent-1', 'flowfact_schema' => self::SCHEMA_MIETE, 'sync_status' => SyncStatus::Uebertragen]);

        // 09:50: Rückzug angefordert (Nachweis deaktivierung_angefordert).
        Carbon::setTestNow('2026-09-12 09:50:00');
        $publication = ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id, 'portal_id' => 'portal-is24', 'portal_name' => 'ImmoScout24',
            'status' => PortalStatus::Aktiv, 'angefordert_at' => now()->subDay(), 'bestaetigt_at' => now()->subDay(),
        ]);
        $publication->zurueckgezogen_at = Carbon::now();
        PortalStatusTransition::apply($publication, PortalStatus::DeaktivierungAngefordert, PortalStatusTransition::QUELLE_OFFLINE_ANGEFORDERT);

        // 10:00: neue Freigabe zur Veröffentlichung.
        Carbon::setTestNow('2026-09-12 10:00:00');
        $release = $this->freigeben($listing, ['portal-is24']);

        // 10:05: FLOWFACT bestätigt die alte Deaktivierung (Nachweis deaktivierung_bestaetigt aus deaktivierung_angefordert).
        Carbon::setTestNow('2026-09-12 10:05:00');
        $this->fake()->on('GET', self::ESTATE_PORTALS, fn () => Http::response([]))->install();
        app(FlowfactPublishingService::class)->refreshStatus($listing->fresh());
        self::assertSame(PortalStatus::DeaktivierungBestaetigt, $publication->fresh()->status);

        self::assertFalse(app(ReleaseGuard::class)->deaktivierungNachFreigabe($listing, $release));
    }

    public function test_die_gegenrichtung_bleibt_erhalten_rueckzug_nach_der_freigabe_stoppt_den_alten_job(): void
    {
        [$listing, $release] = $this->objektMitAlterDeaktivierung();
        $listing->update(['status' => ListingStatus::Veroeffentlicht]);
        $fake = $this->fakePortale();

        Carbon::setTestNow('2026-09-12 10:05:00');
        $rueckzug = app(FlowfactPublishingService::class)->withdraw($listing->fresh(), ['portal-is24']);
        self::assertTrue($rueckzug->ok, $rueckzug->meldung);

        Carbon::setTestNow('2026-09-12 10:10:00');
        (new TransferListingJob($listing->id, null, false, $release->id, true))->handle(app(ListingSyncService::class));

        self::assertSame([], $this->onlinePortale($fake));
        self::assertSame(1, TransferLog::query()->where('zusammenfassung', ReleaseGuard::MELDUNG_DEAKTIVIERUNG)->count());
    }

    /**
     * @return list<Request>
     */
    private function alleAnforderungen(FakeFlowfact $fake): array
    {
        return $fake->requests('POST', self::PUBLISH);
    }
}
