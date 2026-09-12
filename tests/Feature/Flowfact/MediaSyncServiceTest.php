<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Domain\Listing\ListingSnapshot;
use App\Enums\MediaTyp;
use App\Flowfact\Sync\ListingMediaDeletion;
use App\Flowfact\Sync\MediaSyncService;
use App\Models\Listing;
use App\Models\ListingMedia;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\Support\FakeFlowfact;

final class MediaSyncServiceTest extends FlowfactTestCase
{
    private const string ALBUMS = '#^/multimedia-service/albums/schemas/[^/]+$#';

    private const string PRESIGNED = '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+/presigned-url$#';

    private const string REGISTER = '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+$#';

    private const string ITEMS = '#^/multimedia-service/items/entities/[^/]+$#';

    private const string ASSIGN = '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#';

    private const string DELETE_ITEM = '#^/multimedia-service/items/[^/]+$#';

    private const string S3 = '#^/flowfact-media/upload/#';

    protected function setUp(): void
    {
        parent::setUp();

        $this->hinterlegeToken();
        Storage::fake('media');
    }

    private function listingMitBildern(int $anzahl = 2): Listing
    {
        $listing = Listing::factory()->miete()->create();

        for ($i = 0; $i < $anzahl; $i++) {
            $pfad = 'listings/'.$listing->uuid.'/bild-'.$i.'.jpg';
            Storage::disk('media')->put($pfad, $this->beispielbild(80 + $i, 60));

            ListingMedia::factory()->create([
                'listing_id' => $listing->id,
                'typ' => MediaTyp::Bild,
                'dateiname_original' => 'Wohnzimmer Süd '.$i.'.jpg',
                'pfad' => $pfad,
                'sortierung' => $i,
                'titel' => $i === 0 ? 'Titelbild' : null,
            ]);
        }

        return $listing->fresh(['media']);
    }

    /**
     * Momentaufnahme des Live-Stands als Ersatz für eine Freigabeversion (B.6).
     */
    private function snapshot(Listing $listing): ListingSnapshot
    {
        return ListingSnapshot::fromListing($listing->fresh(['media']));
    }

    private function fakeUploadKette(): FakeFlowfact
    {
        $naechsteId = 100;

        return $this->fake()
            ->on('GET', self::ALBUMS, self::albumsResponse())
            ->on('GET', self::PRESIGNED, self::presignedResponse())
            ->on('PUT', self::S3, fn () => Http::response('', 200))
            ->on('POST', self::REGISTER, function (Request $request) use (&$naechsteId) {
                return Http::response(['multimediaItem' => self::multimediaItem(++$naechsteId, (string) $request->data()['fileName'])]);
            })
            ->on('GET', self::ITEMS, fn () => Http::response([self::multimediaItem(101), self::multimediaItem(102)]))
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->on('DELETE', self::DELETE_ITEM, '')
            ->install();
    }

    public function test_upload_kette_je_bild_mit_reihenfolge_und_titelbild_an_position_null(): void
    {
        $listing = $this->listingMitBildern(2);
        $fake = $this->fakeUploadKette();

        $ergebnis = app(MediaSyncService::class)->sync($listing, $this->snapshot($listing), self::SCHEMA_MIETE, 'ent-1');

        self::assertSame([], $ergebnis->warnungen);
        self::assertSame(2, $ergebnis->hochgeladen);
        self::assertSame(1, $fake->count('GET', self::ALBUMS));
        self::assertSame(2, $fake->count('GET', self::PRESIGNED));
        self::assertSame(2, $fake->count('PUT', self::S3));
        self::assertSame(2, $fake->count('POST', self::REGISTER));
        self::assertSame(1, $fake->count('PUT', self::ASSIGN));

        $presigned = $fake->requests('GET', self::PRESIGNED)[0];
        self::assertStringContainsString('contentType=image%2Fjpeg', $presigned->url());
        // Deterministischer Dateiname (Prüfbericht 2026-09-11, Befund 6): uuid-medienid-sha12.ext
        $titelbild = $listing->media()->where('sortierung', 0)->first();
        self::assertStringContainsString('fileName='.$listing->uuid.'-'.$titelbild->id.'-'.substr($titelbild->pruefsumme_sha256, 0, 12).'.jpg', $presigned->url());
        self::assertMatchesRegularExpression('/fileSize=\d+/', $presigned->url());

        $upload = $fake->requests('PUT', self::S3)[0];
        self::assertTrue($upload->hasHeader('Content-Type', 'image/jpeg'));
        self::assertFalse($upload->hasHeader('x-ff-api-token'));
        self::assertSame(IMAGETYPE_JPEG, getimagesizefromstring($upload->body())[2]);

        $register = $fake->requests('POST', self::REGISTER)[0]->data();
        self::assertSame('image/jpeg', $register['contentType']);
        self::assertSame('s3://flowfact-media/upload/abc', $register['itemLink']);
        self::assertSame('Titelbild', $register['title']);
        self::assertSame([['albumName' => 'estate_album', 'categories' => ['images']]], $register['albumAssignments']);
        self::assertArrayNotHasKey('title', $fake->requests('POST', self::REGISTER)[1]->data());

        $zuordnung = $fake->requests('PUT', self::ASSIGN)[0];
        self::assertStringContainsString('albumName=estate_album', $zuordnung->url());
        self::assertStringContainsString('short=false', $zuordnung->url());
        $eintraege = $zuordnung->data()['assignments']['images'];
        self::assertSame(0, $eintraege[0]['sorting']);
        self::assertSame(101, $eintraege[0]['multimedia']['id']);
        self::assertSame(1, $eintraege[1]['sorting']);
        self::assertSame(102, $eintraege[1]['multimedia']['id']);

        $ids = $listing->media()->orderBy('sortierung')->pluck('flowfact_multimedia_id')->all();
        self::assertSame(['101', '102'], $ids);
        self::assertSame(['Titelbild', null], $listing->media()->orderBy('sortierung')->pluck('flowfact_titel')->all(), 'Übertragener Titel wird gemerkt (Befund 4).');

        // Albumauswahl wird je Schema in den Einstellungen gemerkt.
        self::assertSame(['album' => 'estate_album', 'bilder' => 'images', 'dokumente' => 'documents'], $this->settings()->get('flowfact.album_'.self::SCHEMA_MIETE));
    }

    public function test_kein_zweiter_upload_bei_vorhandener_id_und_album_aus_dem_cache(): void
    {
        $listing = $this->listingMitBildern(2);
        // flowfact_titel entspricht dem Titel, sonst würde ein PATCH gesendet (Befund 4).
        $listing->media()->where('sortierung', 0)->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild']);
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images', 'dokumente' => null]);
        $fake = $this->fakeUploadKette();

        $ergebnis = app(MediaSyncService::class)->sync($listing->fresh(['media']), $this->snapshot($listing), self::SCHEMA_MIETE, 'ent-1');

        self::assertSame(1, $ergebnis->hochgeladen);
        self::assertSame(0, $fake->count('GET', self::ALBUMS));
        self::assertSame(1, $fake->count('GET', self::PRESIGNED));
        self::assertSame(1, $fake->count('POST', self::REGISTER));
    }

    public function test_ohne_offene_arbeit_wird_nichts_gesendet(): void
    {
        $listing = $this->listingMitBildern(1);
        // Übertragener Titel entspricht dem lokalen Titel (Befund 4).
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild']);
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images']);
        $fake = $this->fakeUploadKette();

        $service = app(MediaSyncService::class);
        self::assertFalse($service->hatOffeneArbeit($listing, $this->snapshot($listing)));

        $ergebnis = $service->sync($listing->fresh(['media']), $this->snapshot($listing), self::SCHEMA_MIETE, 'ent-1');

        self::assertSame(0, $ergebnis->hochgeladen);
        self::assertSame(0, $fake->count('PUT', self::ASSIGN));
        self::assertSame([], $fake->calls);
    }

    public function test_nur_bilder_und_grundrisse_im_inserat_werden_hochgeladen(): void
    {
        $listing = Listing::factory()->miete()->create();
        $pfad = 'listings/'.$listing->uuid.'/x.jpg';
        Storage::disk('media')->put($pfad, $this->beispielbild());

        ListingMedia::factory()->create(['listing_id' => $listing->id, 'typ' => MediaTyp::Bild, 'pfad' => $pfad, 'im_inserat' => false, 'sortierung' => 0]);
        ListingMedia::factory()->create(['listing_id' => $listing->id, 'typ' => MediaTyp::Dokument, 'pfad' => $pfad, 'mime' => 'application/pdf', 'im_inserat' => true, 'sortierung' => 1]);
        ListingMedia::factory()->create(['listing_id' => $listing->id, 'typ' => MediaTyp::Grundriss, 'pfad' => $pfad, 'im_inserat' => true, 'sortierung' => 2]);
        $fake = $this->fakeUploadKette();

        $ergebnis = app(MediaSyncService::class)->sync($listing->fresh(['media']), $this->snapshot($listing), self::SCHEMA_MIETE, 'ent-1');

        self::assertSame(1, $ergebnis->hochgeladen);
        self::assertSame(1, $fake->count('POST', self::REGISTER));
        self::assertNull($listing->media()->where('typ', MediaTyp::Dokument->value)->first()->flowfact_multimedia_id);
    }

    public function test_lokal_geloeschte_medien_werden_in_flowfact_geloescht(): void
    {
        $listing = $this->listingMitBildern(2);
        $listing->media()->update(['flowfact_multimedia_id' => '555']);
        $listing->media()->where('sortierung', 0)->update(['flowfact_titel' => 'Titelbild']);
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images']);

        // Der Observer merkt das FLOWFACT-Item beim lokalen Löschen vor.
        $listing->media()->where('sortierung', 1)->first()->delete();
        self::assertSame(1, ListingMediaDeletion::query()->where('listing_id', $listing->id)->count());
        self::assertSame('555', ListingMediaDeletion::query()->first()->flowfact_multimedia_id);

        $fake = $this->fakeUploadKette();

        $ergebnis = app(MediaSyncService::class)->sync($listing->fresh(['media']), $this->snapshot($listing), self::SCHEMA_MIETE, 'ent-1');

        self::assertSame(1, $ergebnis->geloescht);
        self::assertSame(1, $fake->count('DELETE', self::DELETE_ITEM));
        self::assertSame(self::BASE.'/multimedia-service/items/555', $fake->requests('DELETE', self::DELETE_ITEM)[0]->url());
        self::assertSame(0, ListingMediaDeletion::query()->count());
        self::assertSame(1, $fake->count('PUT', self::ASSIGN), 'Nach dem Löschen wird die Reihenfolge neu gesetzt.');
    }

    public function test_medium_ohne_flowfact_id_erzeugt_keine_vormerkung(): void
    {
        $listing = $this->listingMitBildern(1);

        $listing->media()->first()->delete();

        self::assertSame(0, ListingMediaDeletion::query()->count());
    }

    public function test_fehlende_datei_erzeugt_warnung(): void
    {
        $listing = Listing::factory()->miete()->create();
        ListingMedia::factory()->create(['listing_id' => $listing->id, 'pfad' => 'listings/fehlt.jpg']);

        $this->fake()
            ->on('GET', self::ALBUMS, self::albumsResponse())
            ->on('GET', self::ITEMS, [])
            ->install();
        $ergebnis = app(MediaSyncService::class)->sync($listing->fresh(['media']), $this->snapshot($listing), self::SCHEMA_MIETE, 'ent-1');

        self::assertStringContainsString('nicht gefunden', $ergebnis->warnungen[0]);
    }

    public function test_fehlendes_bildalbum_erzeugt_warnung_ohne_upload(): void
    {
        $listing = $this->listingMitBildern(1);

        $fake = $this->fake()
            ->on('GET', self::ALBUMS, [['id' => 'a', 'name' => 'docs', 'categories' => [['name' => 'documents', 'allowedContentCategories' => ['DOCUMENT']]]]])
            ->install();
        $ergebnis = app(MediaSyncService::class)->sync($listing, $this->snapshot($listing), self::SCHEMA_MIETE, 'ent-1');

        self::assertStringContainsString('Kein Album mit Bildkategorie', $ergebnis->warnungen[0]);
        self::assertSame(0, $fake->count('GET', self::PRESIGNED));
        self::assertNull($this->settings()->get('flowfact.album_'.self::SCHEMA_MIETE));
    }
}
