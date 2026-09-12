<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Domain\Settings\SettingsRepository;
use App\Enums\ListingStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Mapping\FieldMappingResolver;
use App\Flowfact\Sync\ListingSyncService;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\ListingRelease;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;
use Tests\Feature\Flowfact\Support\FakeFlowfact;

/**
 * Prüfbericht 2026-09-12, Befund 5: Die tatsächlich gesendeten Zielfeldnamen
 * werden je Übertragung gespeichert (Link und Freigabeversion). Die Löschliste
 * des nächsten PATCH entsteht aus dieser Liste, nicht aus einer erneuten
 * Zuordnung der Vorgängerversion mit der aktuellen Feldzuordnung.
 */
final class Regression16GesendeteFelderTest extends FlowfactTestCase
{
    private const string SEARCH = '#^/search-service/schemas/[^/]+$#';

    private const string CREATE = '#^/entity-service/schemas/[^/]+$#';

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

    private function fakeAnlegen(): FakeFlowfact
    {
        return $this->fake()
            ->on('POST', self::SEARCH, self::searchResponse([]))
            ->on('POST', self::CREATE, self::entityResponse('ent-1'))
            ->on('GET', self::ITEMS, [self::multimediaItem(101)])
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->install();
    }

    private function fakeAktualisierung(): FakeFlowfact
    {
        return $this->fake()
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-1', ['yearofconstruction' => ['values' => [1990]]]))
            ->on('PATCH', self::GET_ENTITY, fn () => Http::response('', 200))
            ->on('GET', self::ITEMS, [self::multimediaItem(101)])
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->install();
    }

    private function objekt(): Listing
    {
        $listing = $this->bereitesListing(['baujahr' => 1990]);
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild', 'titel' => 'Titelbild']);

        return $listing->fresh(['price', 'energy', 'media']);
    }

    public function test_geaenderte_feldzuordnung_leert_das_tatsaechlich_gesendete_zielfeld(): void
    {
        $user = User::factory()->admin()->create();
        $listing = $this->objekt();

        // Freigabe 1 mit Standardzuordnung baujahr -> yearofconstruction.
        $r1 = $this->freigeben($listing, []);
        $fake = $this->fakeAnlegen();
        $ergebnis = app(ListingSyncService::class)->sync($listing, $user);
        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1990, $fake->requests('POST', self::CREATE)[0]->data()['yearofconstruction']['values'][0]);

        $link = $listing->flowfactLink()->firstOrFail();
        self::assertContains('yearofconstruction', $link->gesendeteFelder() ?? [], 'Die gesendeten Zielfelder stehen am Link.');
        self::assertContains('yearofconstruction', $r1->fresh()->gesendeteFelder() ?? [], 'Die gesendeten Zielfelder stehen an der übertragenen Version.');

        // Der Admin ändert die Zuordnung; danach wird das Baujahr geleert und erneut freigegeben.
        app(SettingsRepository::class)->set(FieldMappingResolver::FELDZUORDNUNG, ['baujahr' => 'constructionyear_custom']);
        $listing->update(['baujahr' => null]);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);
        $fake = $this->fakeAktualisierung();

        $ergebnis = app(ListingSyncService::class)->sync($listing, $user);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        $body = $fake->requests('PATCH', self::GET_ENTITY)[0]->data();
        self::assertSame(['values' => []], $body['yearofconstruction'], 'Das tatsächlich gesendete Zielfeld wird geleert.');
        self::assertArrayNotHasKey('constructionyear_custom', $body, 'Das nie gesendete neue Zielfeld erhält keine leere Werteliste.');

        $link = $listing->flowfactLink()->firstOrFail();
        self::assertNotContains('yearofconstruction', $link->gesendeteFelder() ?? [], 'Nach dem Leeren gilt das Feld nicht mehr als gesendet.');
        self::assertNotContains('constructionyear_custom', $link->gesendeteFelder() ?? []);
    }

    /**
     * Zuordnung geändert, Wert unverändert: Der Wert geht an das neue Zielfeld,
     * das alte, von Müller FLOW befüllte Zielfeld wird geleert, damit dort
     * kein veralteter Wert stehen bleibt.
     */
    public function test_verschobene_zuordnung_leert_das_alte_zielfeld_und_fuellt_das_neue(): void
    {
        $user = User::factory()->admin()->create();
        $listing = $this->objekt();
        $this->freigeben($listing, []);
        $this->fakeAnlegen();
        self::assertTrue(app(ListingSyncService::class)->sync($listing, $user)->ok);

        app(SettingsRepository::class)->set(FieldMappingResolver::FELDZUORDNUNG, ['baujahr' => 'constructionyear_custom']);
        $listing->update(['titel' => 'Neue Überschrift']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);
        $fake = $this->fakeAktualisierung();

        $ergebnis = app(ListingSyncService::class)->sync($listing, $user);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        $body = $fake->requests('PATCH', self::GET_ENTITY)[0]->data();
        self::assertSame(['values' => [1990]], $body['constructionyear_custom']);
        self::assertSame(['values' => []], $body['yearofconstruction']);
        self::assertSame('constructionyear_custom', $listing->flowfactLink()->firstOrFail()->gesendeteZuordnung()['baujahr'] ?? null);
    }

    /**
     * Ein abgeschaltetes Feld (Zuordnung leer) wird weder gesendet noch
     * geleert: FLOWFACT führt es ab jetzt.
     */
    public function test_abgeschaltete_zuordnung_leert_nichts(): void
    {
        $user = User::factory()->admin()->create();
        $listing = $this->objekt();
        $this->freigeben($listing, []);
        $this->fakeAnlegen();
        self::assertTrue(app(ListingSyncService::class)->sync($listing, $user)->ok);

        app(SettingsRepository::class)->set(FieldMappingResolver::FELDZUORDNUNG, ['baujahr' => null]);
        $listing->update(['titel' => 'Neue Überschrift']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);
        $fake = $this->fakeAktualisierung();

        $ergebnis = app(ListingSyncService::class)->sync($listing, $user);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        $body = $fake->requests('PATCH', self::GET_ENTITY)[0]->data();
        self::assertArrayNotHasKey('yearofconstruction', $body);
        self::assertNotContains(['values' => []], $body);
    }

    public function test_gesendete_felder_werden_nach_jedem_patch_fortgeschrieben(): void
    {
        $user = User::factory()->admin()->create();
        $listing = $this->objekt();
        $this->freigeben($listing, []);
        $this->fakeAnlegen();
        app(ListingSyncService::class)->sync($listing, $user);

        $listing->update(['baujahr' => null]);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $r2 = $this->freigeben($listing, []);
        $this->fakeAktualisierung();

        $ergebnis = app(ListingSyncService::class)->sync($listing, $user);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        $link = $listing->flowfactLink()->firstOrFail();
        self::assertSame($r2->id, $link->release_id);
        self::assertSame($link->gesendeteFelder(), $r2->fresh()->gesendeteFelder());
        self::assertContains('headline', $link->gesendeteFelder() ?? []);
        self::assertNotContains('yearofconstruction', $link->gesendeteFelder() ?? []);
    }

    /**
     * Ein Link aus der Zeit vor der Spalte (ohne Liste) greift auf die Liste
     * der zuletzt übertragenen Version zurück; erst ohne beide wird die
     * Vorgängerversion erneut gemappt (Verhalten aus Regression 05).
     */
    public function test_ohne_liste_am_link_gilt_die_liste_der_uebertragenen_version(): void
    {
        $listing = $this->objekt();
        $listing->update(['status' => ListingStatus::Veroeffentlicht]);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $r1 = $this->freigeben($listing, []);
        $r1->gesendete_felder_json = ['headline', 'identifier', 'yearofconstruction'];
        $r1->saveQuietly();

        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id, 'flowfact_entity_id' => 'ent-1', 'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen, 'uebertragener_inhalt_hash' => $r1->inhalt_hash, 'release_id' => $r1->id,
            'gesendete_felder_json' => null,
        ]);

        // Zustand und Baujahr geleert: nur das Baujahr stand in der gespeicherten Liste.
        $listing->update(['baujahr' => null, 'zustand' => null]);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);
        $fake = $this->fakeAktualisierung();

        $ergebnis = app(ListingSyncService::class)->sync($listing, User::factory()->admin()->create());

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        $body = $fake->requests('PATCH', self::GET_ENTITY)[0]->data();
        self::assertSame(['values' => []], $body['yearofconstruction']);
        self::assertArrayNotHasKey('condition', $body, 'Der Zustand stand nicht in der gespeicherten Liste und wird nicht geleert.');
        self::assertInstanceOf(ListingRelease::class, $r1->fresh());
    }
}
