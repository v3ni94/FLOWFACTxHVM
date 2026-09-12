<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\ListingStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Sync\ListingMediaDeletion;
use App\Flowfact\Sync\ListingSyncService;
use App\Flowfact\Sync\MediaSyncService;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;
use Tests\Feature\Flowfact\Support\FakeFlowfact;

/**
 * Prüfbericht 2026-09-12, Befund 16: Scheitert beim Übertragen einer Drehung
 * (oder einer entzogenen Freigabe) das DELETE des alten Items mit einem
 * allgemeinen API-Fehler, bleibt die alte ID nicht stehen. Das Item wird zur
 * Löschung vorgemerkt, das gedrehte Bild im selben Lauf hochgeladen und die
 * Löschung beim nächsten Lauf wiederholt.
 */
final class Regression17RotationLoeschfehlerTest extends FlowfactTestCase
{
    private const string GET_ENTITY = '#^/entity-service/schemas/[^/]+/entities/[^/]+$#';

    private const string ITEM = '#^/multimedia-service/items/(\d+)$#';

    private const string ITEMS = '#^/multimedia-service/items/entities/[^/]+$#';

    private const string PRESIGNED = '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+/presigned-url$#';

    private const string REGISTER = '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+$#';

    private const string ASSIGN = '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images']);
    }

    /**
     * Version 1 wurde mit einem ungedrehten Bild (Item 101) übertragen; danach
     * wird das Bild um 90 Grad gedreht und erneut freigegeben.
     */
    private function gedrehtesObjekt(): Listing
    {
        $listing = $this->bereitesListing(['status' => ListingStatus::Veroeffentlicht]);
        $medium = $listing->media()->firstOrFail();
        Storage::disk('media')->put($medium->pfad, $this->beispielbild(40, 30));
        $medium->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => null, 'titel' => null]);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $r1 = $this->freigeben($listing, []);

        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id, 'flowfact_entity_id' => 'ent-1', 'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen, 'uebertragener_inhalt_hash' => app(ListingContentHasher::class)->hash($listing),
            'release_id' => $r1->id,
        ]);

        $medium->update(['rotation' => 90]);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);

        return $listing;
    }

    private function fakeMitLoeschantwort(callable $loeschantwort): FakeFlowfact
    {
        return $this->fake()
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->on('PATCH', self::GET_ENTITY, fn () => Http::response('', 200))
            ->on('DELETE', self::ITEM, $loeschantwort)
            ->on('GET', self::ITEMS, [])
            ->on('GET', self::PRESIGNED, self::presignedResponse())
            ->on('PUT', '#^/flowfact-media/upload/#', fn () => Http::response('', 200))
            ->on('POST', self::REGISTER, fn (Request $request) => Http::response(['multimediaItem' => self::multimediaItem(202, (string) $request->data()['fileName'])]))
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->install();
    }

    public function test_gescheitertes_loeschen_merkt_das_item_vor_und_laedt_das_gedrehte_bild_hoch(): void
    {
        $listing = $this->gedrehtesObjekt();
        $fake = $this->fakeMitLoeschantwort(fn () => Http::response(['message' => 'internal error'], 500));

        $ergebnis = app(ListingSyncService::class)->sync($listing, User::factory()->admin()->create());

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('DELETE', self::ITEM));
        self::assertSame(1, $fake->count('POST', self::REGISTER), 'Das gedrehte Bild wird trotz gescheitertem Löschen hochgeladen.');
        self::assertStringContainsString('-r90.jpg', (string) $fake->requests('POST', self::REGISTER)[0]->data()['fileName']);

        $medium = $listing->media()->firstOrFail();
        self::assertSame('202', $medium->flowfact_multimedia_id, 'Die alte ID bleibt nicht stehen.');

        $vormerkung = ListingMediaDeletion::query()->where('listing_id', $listing->id)->get();
        self::assertCount(1, $vormerkung);
        self::assertSame('101', $vormerkung->first()->flowfact_multimedia_id);

        $warnung = array_values(array_filter($ergebnis->warnungen, fn (string $w): bool => str_contains($w, 'Löschung wurde vorgemerkt')));
        self::assertCount(1, $warnung, implode(' | ', $ergebnis->warnungen));
        self::assertTrue(app(MediaSyncService::class)->hatOffeneArbeit($listing->fresh(), $listing->latestRelease()->firstOrFail()->snapshot()), 'Die Vormerkung gilt als offene Arbeit.');
    }

    public function test_die_vorgemerkte_loeschung_wird_im_naechsten_lauf_nachgeholt(): void
    {
        $listing = $this->gedrehtesObjekt();
        $admin = User::factory()->admin()->create();
        $scheitert = true;
        $fake = $this->fakeMitLoeschantwort(function () use (&$scheitert) {
            return $scheitert ? Http::response(['message' => 'internal error'], 500) : Http::response('', 204);
        });

        self::assertTrue(app(ListingSyncService::class)->sync($listing, $admin)->ok);
        self::assertSame(1, ListingMediaDeletion::query()->where('listing_id', $listing->id)->count());
        self::assertSame(1, $fake->count('PATCH', self::GET_ENTITY));

        // Nächster Lauf derselben Freigabe (offene Arbeit: Vormerkung), FLOWFACT löscht jetzt.
        $scheitert = false;
        $ergebnis = app(ListingSyncService::class)->sync($listing->fresh(['price', 'energy', 'media']), $admin);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        $loeschungen = $fake->requests('DELETE', self::ITEM);
        self::assertCount(2, $loeschungen, 'Genau eine Wiederholung der Löschung, kein Löschen des neuen Items.');
        self::assertStringEndsWith('/items/101', (string) parse_url($loeschungen[1]->url(), PHP_URL_PATH));
        self::assertSame(0, ListingMediaDeletion::query()->where('listing_id', $listing->id)->count(), 'Die Vormerkung ist abgearbeitet.');
        self::assertSame('202', $listing->media()->firstOrFail()->flowfact_multimedia_id, 'Das gedrehte Bild bleibt, es wird nicht erneut hochgeladen.');
        self::assertSame(1, $fake->count('POST', self::REGISTER));
        self::assertSame(1, $fake->count('PATCH', self::GET_ENTITY), 'Ohne Inhaltsänderung kein erneuter PATCH.');
    }

    public function test_erfolgreiches_loeschen_erzeugt_keine_vormerkung(): void
    {
        $listing = $this->gedrehtesObjekt();
        $fake = $this->fakeMitLoeschantwort(fn () => Http::response('', 204));

        $ergebnis = app(ListingSyncService::class)->sync($listing, User::factory()->admin()->create());

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('DELETE', self::ITEM));
        self::assertSame(0, ListingMediaDeletion::query()->where('listing_id', $listing->id)->count());
        self::assertSame('202', $listing->media()->firstOrFail()->flowfact_multimedia_id);
        self::assertSame([], array_values(array_filter($ergebnis->warnungen, fn (string $w): bool => str_contains($w, 'gelöscht'))));
    }
}
