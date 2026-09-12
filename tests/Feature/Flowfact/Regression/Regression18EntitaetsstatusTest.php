<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Enums\PortalStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Flowfact\Sync\ListingSyncService;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\ListingPortalPublication;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;
use Tests\Feature\Flowfact\Support\FakeFlowfact;

/**
 * Prüfbericht 2026-09-12, Befund 10 (Connector-Anteil): Fehlt dem handelnden
 * Benutzer das Veröffentlichungsrecht oder handelt kein Benutzer, wird eine
 * neue Entität mit status inactive angelegt und eine Warnung ausgegeben.
 * Vor einer Veröffentlichung durch einen Berechtigten wird die Entität
 * erneut übertragen und aktiviert. Eine bereits aktive Entität wird ohne
 * Recht nicht deaktiviert.
 */
final class Regression18EntitaetsstatusTest extends FlowfactTestCase
{
    private const string SEARCH = '#^/search-service/schemas/[^/]+$#';

    private const string CREATE = '#^/entity-service/schemas/[^/]+$#';

    private const string GET_ENTITY = '#^/entity-service/schemas/[^/]+/entities/[^/]+$#';

    private const string ITEMS = '#^/multimedia-service/items/entities/[^/]+$#';

    private const string ASSIGN = '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#';

    private const string PORTALS = '#^/portal-management-service/portals$#';

    private const string PUBLISH = '#^/portal-management-service/publish$#';

    private const string ESTATE_PORTALS = '#^/portal-management-service/estates/[^/]+/portals$#';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images']);
    }

    private function objekt(): Listing
    {
        $listing = $this->bereitesListing();
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild', 'titel' => 'Titelbild']);

        return $listing->fresh(['price', 'energy', 'media']);
    }

    private function fakeAnlegen(): FakeFlowfact
    {
        return $this->fake()
            ->on('POST', self::SEARCH, self::searchResponse([]))
            ->on('POST', self::CREATE, self::entityResponse('ent-1'))
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->on('PATCH', self::GET_ENTITY, fn () => Http::response('', 200))
            ->on('GET', self::ITEMS, [self::multimediaItem(101)])
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, fn () => Http::response('', 200))
            ->on('GET', self::ESTATE_PORTALS, fn () => Http::response([]))
            ->install();
    }

    public function test_ohne_veroeffentlichungsrecht_wird_die_entitaet_inaktiv_angelegt(): void
    {
        $mitarbeiter = User::factory()->ohneVeroeffentlichungsrecht()->create();
        $listing = $this->objekt();
        $this->freigeben($listing, []);
        $fake = $this->fakeAnlegen();

        $ergebnis = app(ListingSyncService::class)->sync($listing, $mitarbeiter);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(['values' => ['inactive']], $fake->requests('POST', self::CREATE)[0]->data()['status']);
        self::assertContains(ListingSyncService::WARNUNG_INAKTIV_ANGELEGT, $ergebnis->warnungen);
        self::assertSame(ListingFlowfactLink::STATUS_INAKTIV, $listing->flowfactLink()->firstOrFail()->flowfact_status);
    }

    public function test_ohne_handelnden_benutzer_wird_die_entitaet_inaktiv_angelegt(): void
    {
        $listing = $this->objekt();
        $this->freigeben($listing, []);
        $fake = $this->fakeAnlegen();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(['values' => ['inactive']], $fake->requests('POST', self::CREATE)[0]->data()['status']);
        self::assertContains(ListingSyncService::WARNUNG_INAKTIV_ANGELEGT, $ergebnis->warnungen);
    }

    public function test_mit_veroeffentlichungsrecht_wird_die_entitaet_aktiv_angelegt(): void
    {
        $listing = $this->objekt();
        $this->freigeben($listing, []);
        $fake = $this->fakeAnlegen();

        $ergebnis = app(ListingSyncService::class)->sync($listing, User::factory()->create(['darf_veroeffentlichen' => true]));

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(['values' => ['active']], $fake->requests('POST', self::CREATE)[0]->data()['status']);
        self::assertNotContains(ListingSyncService::WARNUNG_INAKTIV_ANGELEGT, $ergebnis->warnungen);
        self::assertSame(ListingFlowfactLink::STATUS_AKTIV, $listing->flowfactLink()->firstOrFail()->flowfact_status);
    }

    /**
     * Die Veröffentlichung durch einen Berechtigten aktiviert die zuvor
     * inaktiv angelegte Entität, bevor POST /publish ONLINE gesendet wird,
     * auch bei unveränderter Freigabeversion.
     */
    public function test_veroeffentlichung_durch_berechtigten_aktiviert_die_inaktive_entitaet_vor_dem_publish(): void
    {
        $mitarbeiter = User::factory()->ohneVeroeffentlichungsrecht()->create();
        $listing = $this->objekt();
        $release = $this->freigeben($listing, ['portal-is24']);
        $fake = $this->fakeAnlegen();
        self::assertTrue(app(ListingSyncService::class)->sync($listing, $mitarbeiter)->ok);
        $link = $listing->flowfactLink()->firstOrFail();
        self::assertSame(SyncStatus::Uebertragen, $link->sync_status);
        self::assertSame($release->id, $link->release_id);
        self::assertTrue($link->inaktivGesendet());

        $ergebnis = app(FlowfactPublishingService::class)->publish($listing->fresh(), ['portal-is24'], User::factory()->admin()->create());

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        $patches = $fake->requests('PATCH', self::GET_ENTITY);
        self::assertCount(1, $patches, 'Die Entität wird vor der Veröffentlichung erneut übertragen.');
        self::assertSame(['values' => ['active']], $patches[0]->data()['status']);
        self::assertSame(1, $fake->count('POST', self::PUBLISH));
        self::assertSame('ONLINE', $fake->requests('POST', self::PUBLISH)[0]->data()['entries'][0]['targetStatus']);
        self::assertSame(ListingFlowfactLink::STATUS_AKTIV, $link->fresh()->flowfact_status);
        self::assertSame(PortalStatus::Angefordert, ListingPortalPublication::query()->firstOrFail()->status);
    }

    public function test_eine_aktive_entitaet_wird_ohne_recht_nicht_deaktiviert(): void
    {
        $listing = $this->objekt();
        $release = $this->freigeben($listing, []);
        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id, 'flowfact_entity_id' => 'ent-1', 'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen, 'uebertragener_inhalt_hash' => $release->inhalt_hash, 'release_id' => $release->id,
            'flowfact_status' => null, // ältere Zeile: immer aktiv gesendet
        ]);
        $fake = $this->fakeAnlegen();

        $ergebnis = app(ListingSyncService::class)->sync($listing, User::factory()->ohneVeroeffentlichungsrecht()->create());

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(0, $fake->count('PATCH', self::GET_ENTITY), 'Kein PATCH und damit kein status inactive.');
        self::assertNotContains(ListingSyncService::WARNUNG_INAKTIV_ANGELEGT, $ergebnis->warnungen);
        self::assertSame('Keine Änderungen seit der letzten Übertragung.', $ergebnis->meldung);
    }
}
