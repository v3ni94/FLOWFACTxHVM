<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\ListingStatus;
use App\Enums\StellplatzTyp;
use App\Enums\SyncStatus;
use App\Flowfact\Sync\ListingSyncService;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;
use Tests\Feature\Flowfact\Support\FakeFlowfact;

/**
 * Prüfbericht 2026-09-11, Befund 5, und Masterprompt Abschnitt 23: Lokal
 * geleerte, zugeordnete Felder gehen beim PATCH als { "values": [] } an
 * FLOWFACT, damit der alte Wert dort gelöscht wird. Seit Welle 3 nur mit
 * Absicht: ausschließlich Felder, die mit der zuletzt übertragenen
 * Freigabeversion tatsächlich gesendet wurden. Felder, die Müller FLOW nie
 * gesendet hat, werden nie gelöscht. Abschaltbar über
 * flowfact.leere_felder_loeschen.
 */
final class Regression05PatchLoeschtGeleerteFelderTest extends FlowfactTestCase
{
    private const string GET_ENTITY = '#^/entity-service/schemas/[^/]+/entities/[^/]+$#';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images']);
    }

    /**
     * Version 1 wurde mit Baujahr, Zustand und Stellplatztyp übertragen.
     *
     * @param  array<string, mixed>  $attribute
     */
    private function uebertragenesListing(array $attribute = []): Listing
    {
        $listing = $this->bereitesListing(array_merge([
            'status' => ListingStatus::Veroeffentlicht,
            'baujahr' => 1998,
            'stellplatz_typ' => StellplatzTyp::Tiefgarage,
        ], $attribute));
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $release = $this->freigeben($listing, []);

        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id,
            'flowfact_entity_id' => 'ent-1',
            'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen,
            'uebertragener_inhalt_hash' => app(ListingContentHasher::class)->hash($listing),
            'release_id' => $release->id,
            'flowfact_last_modified' => '1757590000000',
        ]);

        return $listing;
    }

    private function fakeAktualisierung(): FakeFlowfact
    {
        return $this->fake()
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-1', ['yearofconstruction' => ['values' => [1998]]]))
            ->on('PATCH', self::GET_ENTITY, fn () => Http::response('', 200))
            ->on('GET', '#^/multimedia-service/items/entities/[^/]+$#', [self::multimediaItem(101)])
            ->on('PUT', '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#', ['assignments' => []])
            ->install();
    }

    public function test_geleerte_felder_werden_im_patch_als_leere_werteliste_gesendet(): void
    {
        $listing = $this->uebertragenesListing();
        $listing->update(['baujahr' => null, 'zustand' => null, 'stellplatz_typ' => null]);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);
        $fake = $this->fakeAktualisierung();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('PATCH', self::GET_ENTITY));

        $body = $fake->requests('PATCH', self::GET_ENTITY)[0]->data();
        self::assertSame(['values' => []], $body['yearofconstruction'], 'Löschbefehl für das Baujahr.');
        self::assertSame(['values' => []], $body['condition']);
        self::assertSame(['values' => []], $body['parking']);
        self::assertSame(['values' => [$listing->titel]], $body['headline'], 'Gesetzte Felder bleiben unverändert.');
    }

    /**
     * Masterprompt Abschnitt 23: Ein Feld, das mit keiner früheren Freigabe
     * gesendet wurde, darf nicht gelöscht werden, auch wenn es zugeordnet und
     * lokal leer ist.
     */
    public function test_nie_gesendete_felder_werden_nicht_geloescht(): void
    {
        $listing = $this->uebertragenesListing(['stellplatz_typ' => null, 'schlafzimmer' => null]);
        $listing->update(['baujahr' => null]);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);
        $fake = $this->fakeAktualisierung();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        $body = $fake->requests('PATCH', self::GET_ENTITY)[0]->data();
        self::assertSame(['values' => []], $body['yearofconstruction'], 'Das Baujahr war in Version 1 gesetzt und wird gelöscht.');
        self::assertArrayNotHasKey('parking', $body, 'Der Stellplatztyp wurde nie gesendet und wird nicht gelöscht.');
        self::assertArrayNotHasKey('numberbedrooms', $body, 'Schlafzimmer wurden nie gesendet und werden nicht gelöscht.');
    }

    /**
     * Ohne frühere Freigabe gibt es keine Vergleichsbasis: keine Löschbefehle.
     */
    public function test_erste_freigabe_sendet_keine_loeschbefehle(): void
    {
        $listing = $this->bereitesListing(['status' => ListingStatus::Veroeffentlicht, 'baujahr' => null]);
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);
        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id,
            'flowfact_entity_id' => 'ent-1',
            'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen,
            'uebertragener_inhalt_hash' => 'alter-stand-vor-freigabemodell',
        ]);
        $fake = $this->fakeAktualisierung();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        $body = $fake->requests('PATCH', self::GET_ENTITY)[0]->data();
        self::assertArrayNotHasKey('yearofconstruction', $body);
        self::assertNotContains(['values' => []], $body);
    }

    public function test_loeschsemantik_ist_ueber_die_einstellung_abschaltbar(): void
    {
        $this->settings()->set(ListingSyncService::LEERE_FELDER_LOESCHEN, false);
        $listing = $this->uebertragenesListing();
        $listing->update(['baujahr' => null]);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);
        $fake = $this->fakeAktualisierung();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertArrayNotHasKey('yearofconstruction', $fake->requests('PATCH', self::GET_ENTITY)[0]->data());
    }

    public function test_anlegen_sendet_keine_leeren_wertelisten(): void
    {
        $listing = $this->bereitesListing(['baujahr' => null]);
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);

        $fake = $this->fake()
            ->on('POST', '#^/search-service/schemas/[^/]+$#', self::searchResponse([]))
            ->on('POST', '#^/entity-service/schemas/[^/]+$#', self::entityResponse('ent-neu'))
            ->on('GET', '#^/multimedia-service/items/entities/[^/]+$#', [self::multimediaItem(101)])
            ->on('PUT', '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#', ['assignments' => []])
            ->install();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        $body = $fake->requests('POST', '#^/entity-service/schemas/[^/]+$#')[0]->data();
        self::assertArrayNotHasKey('yearofconstruction', $body);
        self::assertNotContains(['values' => []], $body);
    }
}
