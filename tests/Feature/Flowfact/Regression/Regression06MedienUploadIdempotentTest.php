<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Enums\ListingStatus;
use App\Flowfact\Sync\ListingSyncService;
use App\Flowfact\Sync\MediaSyncService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;

/**
 * Prüfbericht 2026-09-11, Befund 6: Der Bildupload ist auch nach einer
 * Zeitüberschreitung beim Registrieren idempotent. Der Dateiname ist
 * deterministisch, vorhandene Items werden vor dem Upload gelesen und über
 * den Dateinamen übernommen.
 */
final class Regression06MedienUploadIdempotentTest extends FlowfactTestCase
{
    private const string SEARCH = '#^/search-service/schemas/[^/]+$#';

    private const string CREATE = '#^/entity-service/schemas/[^/]+$#';

    private const string GET_ENTITY = '#^/entity-service/schemas/[^/]+/entities/[^/]+$#';

    private const string ALBUMS = '#^/multimedia-service/albums/schemas/[^/]+$#';

    private const string PRESIGNED = '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+/presigned-url$#';

    private const string REGISTER = '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+$#';

    private const string ITEMS = '#^/multimedia-service/items/entities/[^/]+$#';

    private const string ASSIGN = '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#';

    private const string S3 = '#^/flowfact-media/upload/#';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
    }

    public function test_zeitueberschreitung_beim_registrieren_fuehrt_nicht_zum_zweiten_upload(): void
    {
        $listing = $this->bereitesListing(['status' => ListingStatus::Bereit]);
        $medium = $listing->media()->first();
        Storage::disk('media')->put($medium->pfad, $this->beispielbild());
        $this->freigeben($listing, []);

        $erwarteterDateiname = app(MediaSyncService::class)->dateiname($listing, $medium, 'jpg');
        self::assertSame($listing->uuid.'-'.$medium->id.'-'.substr($medium->pruefsumme_sha256, 0, 12).'.jpg', $erwarteterDateiname);

        $registriert = false;

        $fake = $this->fake()
            ->on('POST', self::SEARCH, self::searchResponse([]))
            ->on('POST', self::CREATE, self::entityResponse('ent-1'))
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->on('PATCH', self::GET_ENTITY, fn () => Http::response('', 200))
            ->on('GET', self::ALBUMS, self::albumsResponse())
            ->on('GET', self::PRESIGNED, self::presignedResponse())
            ->on('PUT', self::S3, fn () => Http::response('', 200))
            ->on('POST', self::REGISTER, function () use (&$registriert) {
                // FLOWFACT legt das Item an, die Antwort erreicht uns nicht.
                $registriert = true;

                throw new ConnectionException('cURL error 28: Operation timed out');
            })
            ->on('GET', self::ITEMS, function () use (&$registriert, $erwarteterDateiname) {
                return Http::response($registriert ? [array_merge(self::multimediaItem(201, $erwarteterDateiname), ['title' => 'Titelbild'])] : []);
            })
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->install();

        $sync = app(ListingSyncService::class);

        $erster = $sync->sync($listing);
        self::assertFalse($erster->ok);
        self::assertStringContainsString('fileName='.rawurlencode($erwarteterDateiname), $fake->requests('GET', self::PRESIGNED)[0]->url());
        self::assertSame($erwarteterDateiname, $fake->requests('POST', self::REGISTER)[0]->data()['fileName']);

        $zweiter = $sync->sync($listing->fresh(['price', 'energy', 'media']));
        self::assertTrue($zweiter->ok, $zweiter->meldung);

        self::assertSame(1, $fake->count('POST', self::CREATE), 'Entität wird nur einmal angelegt.');
        self::assertSame(1, $fake->count('GET', self::PRESIGNED), 'Keine zweite Upload-URL für dasselbe Bild.');
        self::assertSame(1, $fake->count('PUT', self::S3), 'Das Bild wird nur einmal hochgeladen.');
        self::assertSame(1, $fake->count('POST', self::REGISTER), 'Das Bild wird nur einmal registriert.');
        self::assertSame(2, $fake->count('GET', self::ITEMS), 'Items werden je Lauf genau einmal gelesen.');

        $medium->refresh();
        self::assertSame('201', (string) $medium->flowfact_multimedia_id, 'Das vorhandene Item wird übernommen.');
        self::assertSame('Titelbild', $medium->flowfact_titel);
        self::assertSame(1, $fake->count('PUT', self::ASSIGN));
    }

    public function test_ohne_treffer_in_den_items_wird_hochgeladen(): void
    {
        $listing = $this->bereitesListing(['status' => ListingStatus::Bereit]);
        $medium = $listing->media()->first();
        Storage::disk('media')->put($medium->pfad, $this->beispielbild());
        $this->freigeben($listing, []);

        $fake = $this->fake()
            ->on('POST', self::SEARCH, self::searchResponse([]))
            ->on('POST', self::CREATE, self::entityResponse('ent-1'))
            ->on('GET', self::ALBUMS, self::albumsResponse())
            ->on('GET', self::PRESIGNED, self::presignedResponse())
            ->on('PUT', self::S3, fn () => Http::response('', 200))
            ->on('POST', self::REGISTER, fn (Request $request) => Http::response(['multimediaItem' => self::multimediaItem(301, (string) $request->data()['fileName'])]))
            ->on('GET', self::ITEMS, [self::multimediaItem(299, 'fremdes-bild.jpg')])
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->install();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('POST', self::REGISTER));
        self::assertSame('301', (string) $medium->fresh()->flowfact_multimedia_id);
    }
}
