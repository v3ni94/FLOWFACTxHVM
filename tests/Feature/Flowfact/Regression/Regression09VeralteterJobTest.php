<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Flowfact\Sync\Jobs\RefreshPortalStatusJob;
use App\Flowfact\Sync\Jobs\TransferListingJob;
use App\Flowfact\Sync\ListingSyncService;
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
 * Masterprompt Abschnitt 19 und 24, Masterprompt-Abgleich B.6 und B.8: Jobs
 * tragen die release_id und brechen ab, wenn eine neuere Version existiert,
 * das Objekt archiviert ist oder nach der Freigabe eine Deaktivierung
 * angefordert wurde. Ein alter Job darf nie wieder veröffentlichen.
 */
final class Regression09VeralteterJobTest extends FlowfactTestCase
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
     * @return array{0: Listing, 1: ListingRelease}
     */
    private function uebertragenesListingMitFreigabe(): array
    {
        $listing = $this->bereitesListing();
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild', 'titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $release = $this->freigeben($listing, ['portal-is24']);

        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id,
            'flowfact_entity_id' => 'ent-1',
            'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen,
            'uebertragener_inhalt_hash' => app(ListingContentHasher::class)->hash($listing),
            'release_id' => $release->id,
        ]);

        return [$listing, $release];
    }

    private function fakePortale(): FakeFlowfact
    {
        return $this->fake()
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, fn () => Http::response('', 200))
            ->on('GET', self::ESTATE_PORTALS, fn () => Http::response([]))
            ->install();
    }

    /**
     * @return list<Request>
     */
    private function onlineAnforderungen(FakeFlowfact $fake): array
    {
        return array_values(array_filter(
            $fake->requests('POST', self::PUBLISH),
            fn (Request $request): bool => ($request->data()['entries'][0]['targetStatus'] ?? null) === 'ONLINE',
        ));
    }

    public function test_veraltete_freigabe_wird_ohne_api_aufruf_uebersprungen(): void
    {
        [$listing, $alt] = $this->uebertragenesListingMitFreigabe();
        Carbon::setTestNow('2026-09-12 10:05:00');
        $listing->update(['titel' => 'Neuer Titel']);
        $this->freigeben($listing->fresh(['price', 'energy', 'media']), ['portal-is24']);
        Http::fake();

        (new TransferListingJob($listing->id, null, false, $alt->id, true))->handle(app(ListingSyncService::class), app(ReleaseGuard::class));

        Http::assertNothingSent();
        $log = TransferLog::query()->where('listing_id', $listing->id)->latest('id')->first();
        self::assertNotNull($log);
        self::assertSame(ReleaseGuard::MELDUNG_VERALTET, $log->zusammenfassung);
        self::assertSame($alt->id, $log->details['release_id']);
    }

    public function test_archiviertes_objekt_wird_uebersprungen(): void
    {
        [$listing, $release] = $this->uebertragenesListingMitFreigabe();
        $listing->update(['status' => ListingStatus::Archiviert]);
        Http::fake();

        (new TransferListingJob($listing->id, null, false, $release->id))->handle(app(ListingSyncService::class));
        (new RefreshPortalStatusJob($listing->id, $release->id))->handle(app(PublishingService::class));

        Http::assertNothingSent();
        self::assertSame(2, TransferLog::query()->where('listing_id', $listing->id)->where('zusammenfassung', ReleaseGuard::MELDUNG_ARCHIVIERT)->count());
    }

    public function test_refresh_job_mit_veralteter_freigabe_liest_nicht_nach(): void
    {
        [$listing, $alt] = $this->uebertragenesListingMitFreigabe();
        ListingPortalPublication::factory()->create(['listing_id' => $listing->id, 'portal_id' => 'portal-is24', 'status' => PortalStatus::Angefordert, 'angefordert_at' => now()]);
        $this->freigeben($listing, ['portal-is24']);
        Http::fake();

        (new RefreshPortalStatusJob($listing->id, $alt->id))->handle(app(PublishingService::class));

        Http::assertNothingSent();
        self::assertSame(1, TransferLog::query()->where('zusammenfassung', ReleaseGuard::MELDUNG_VERALTET)->count());
    }

    /**
     * B.8: Deaktivierung während wartender Jobs verhindert die Wiederveröffentlichung.
     * Ablauf: Veröffentlichung angefordert (Job mit Freigabe wartet), Benutzer
     * zieht zurück, danach läuft der alte Job: kein POST /publish mit ONLINE.
     */
    public function test_rueckzug_nach_der_freigabe_verhindert_die_veroeffentlichung_durch_den_alten_job(): void
    {
        [$listing, $release] = $this->uebertragenesListingMitFreigabe();
        $listing->update(['status' => ListingStatus::Veroeffentlicht]);
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-is24',
            'portal_name' => 'ImmoScout24',
            'status' => PortalStatus::Angefordert,
            'angefordert_at' => Carbon::now(),
            'release_id' => $release->id,
        ]);
        $fake = $this->fakePortale();

        // Der Benutzer zieht fünf Minuten nach der Freigabe zurück.
        Carbon::setTestNow('2026-09-12 10:05:00');
        $rueckzug = app(FlowfactPublishingService::class)->withdraw($listing->fresh(), ['portal-is24']);
        self::assertTrue($rueckzug->ok, $rueckzug->meldung);
        self::assertSame(1, $fake->count('POST', self::PUBLISH));
        self::assertSame('OFFLINE', $fake->requests('POST', self::PUBLISH)[0]->data()['entries'][0]['targetStatus']);

        // Der wartende Job (Fortsetzung der Veröffentlichung) läuft erst danach.
        Carbon::setTestNow('2026-09-12 10:10:00');
        (new TransferListingJob($listing->id, null, false, $release->id, true))->handle(app(ListingSyncService::class));

        self::assertSame([], $this->onlineAnforderungen($fake), 'Kein POST /publish mit ONLINE nach dem Rückzug.');
        self::assertSame(1, TransferLog::query()->where('zusammenfassung', ReleaseGuard::MELDUNG_DEAKTIVIERUNG)->count());
        self::assertNotSame(PortalStatus::Angefordert, ListingPortalPublication::query()->first()->status);
    }

    /**
     * Gegenprobe: ohne Rückzug fordert der Job nach vollständiger Übertragung
     * die Veröffentlichung für die freigegebenen Portale an (Befund 15).
     */
    public function test_ohne_rueckzug_fordert_der_job_die_veroeffentlichung_an(): void
    {
        [$listing, $release] = $this->uebertragenesListingMitFreigabe();
        $fake = $this->fakePortale();

        (new TransferListingJob($listing->id, null, false, $release->id, true))->handle(app(ListingSyncService::class));

        self::assertCount(1, $this->onlineAnforderungen($fake));
        $publication = ListingPortalPublication::query()->first();
        self::assertSame(PortalStatus::Angefordert, $publication->status);
        self::assertSame($release->id, $publication->release_id);
        self::assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status);
    }
}
