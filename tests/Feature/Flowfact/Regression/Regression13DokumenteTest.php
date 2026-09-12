<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Domain\Listing\ListingSnapshot;
use App\Enums\MediaTyp;
use App\Flowfact\Sync\MediaSyncService;
use App\Models\Listing;
use App\Models\ListingMedia;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;
use Tests\Feature\Flowfact\Support\FakeFlowfact;

/**
 * Masterprompt Abschnitt 14: Dokumente und Energieausweise bleiben intern,
 * bis sie freigegeben sind. Freigegebene Dokumente gehen unverändert in die
 * Dokumentkategorie des Albums; ohne Dokumentkategorie entsteht eine Warnung.
 * Wird die Freigabe entzogen, wird das Item in FLOWFACT gelöscht.
 */
final class Regression13DokumenteTest extends FlowfactTestCase
{
    private const string ALBUMS = '#^/multimedia-service/albums/schemas/[^/]+$#';

    private const string PRESIGNED = '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+/presigned-url$#';

    private const string REGISTER = '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+$#';

    private const string ITEMS = '#^/multimedia-service/items/entities/[^/]+$#';

    private const string ASSIGN = '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#';

    private const string ITEM = '#^/multimedia-service/items/[^/]+$#';

    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        Storage::fake('media');
    }

    private function listingMitBildUndDokument(bool $dokumentFreigegeben): Listing
    {
        $listing = Listing::factory()->miete()->create();

        $bildPfad = 'listings/'.$listing->uuid.'/bild.jpg';
        Storage::disk('media')->put($bildPfad, $this->beispielbild());
        ListingMedia::factory()->create(['listing_id' => $listing->id, 'typ' => MediaTyp::Bild, 'pfad' => $bildPfad, 'sortierung' => 0, 'flowfact_multimedia_id' => '101', 'flowfact_titel' => null]);

        $pdfPfad = 'listings/'.$listing->uuid.'/expose.pdf';
        Storage::disk('media')->put($pdfPfad, self::PDF);
        ListingMedia::factory()->dokument()->create([
            'listing_id' => $listing->id,
            'pfad' => $pdfPfad,
            'dateiname_original' => 'Exposé Musterstraße.pdf',
            'sortierung' => 1,
            'im_inserat' => true,
            'freigegeben' => $dokumentFreigegeben,
            'titel' => 'Exposé',
        ]);

        return $listing->fresh(['media']);
    }

    private function fakeUpload(): FakeFlowfact
    {
        return $this->fake()
            ->on('GET', self::ALBUMS, self::albumsResponse())
            ->on('GET', self::PRESIGNED, self::presignedResponse())
            ->on('PUT', '#^/flowfact-media/upload/#', fn () => Http::response('', 200))
            ->on('POST', self::REGISTER, fn (Request $request) => Http::response(['multimediaItem' => array_merge(self::multimediaItem(301, (string) $request->data()['fileName']), ['contentCategory' => 'DOCUMENT'])]))
            ->on('GET', self::ITEMS, [self::multimediaItem(101)])
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->on('DELETE', self::ITEM, '')
            ->install();
    }

    public function test_freigegebenes_dokument_wird_unveraendert_in_die_dokumentkategorie_geladen(): void
    {
        $listing = $this->listingMitBildUndDokument(true);
        $fake = $this->fakeUpload();

        $ergebnis = app(MediaSyncService::class)->sync($listing, ListingSnapshot::fromListing($listing), self::SCHEMA_MIETE, 'ent-1');

        self::assertSame([], $ergebnis->warnungen);
        self::assertSame(1, $ergebnis->hochgeladen);

        $presigned = $fake->requests('GET', self::PRESIGNED)[0];
        self::assertStringContainsString('contentType=application%2Fpdf', $presigned->url());
        self::assertStringContainsString('.pdf', $presigned->url());

        $upload = $fake->requests('PUT', '#^/flowfact-media/upload/#')[0];
        self::assertSame(self::PDF, $upload->body(), 'Dokumente werden nicht verändert.');
        self::assertTrue($upload->hasHeader('Content-Type', 'application/pdf'));

        $register = $fake->requests('POST', self::REGISTER)[0]->data();
        self::assertSame('application/pdf', $register['contentType']);
        self::assertSame('Exposé', $register['title']);
        self::assertSame([['albumName' => 'estate_album', 'categories' => ['documents']]], $register['albumAssignments']);
        self::assertSame('301', (string) $listing->media()->where('typ', MediaTyp::Dokument->value)->first()->flowfact_multimedia_id);

        // Die Bildreihenfolge enthält nur Bilder.
        $eintraege = $fake->requests('PUT', self::ASSIGN)[0]->data()['assignments']['images'];
        self::assertCount(1, $eintraege);
        self::assertSame(101, $eintraege[0]['multimedia']['id']);
    }

    public function test_nicht_freigegebenes_dokument_bleibt_intern(): void
    {
        $listing = $this->listingMitBildUndDokument(false);
        $fake = $this->fakeUpload();

        $ergebnis = app(MediaSyncService::class)->sync($listing, ListingSnapshot::fromListing($listing), self::SCHEMA_MIETE, 'ent-1');

        self::assertSame(0, $ergebnis->hochgeladen);
        self::assertSame(0, $fake->count('POST', self::REGISTER));
        self::assertNull($listing->media()->where('typ', MediaTyp::Dokument->value)->first()->flowfact_multimedia_id);
    }

    public function test_ohne_dokumentkategorie_entsteht_eine_warnung(): void
    {
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images', 'dokumente' => null]);
        $listing = $this->listingMitBildUndDokument(true);
        $fake = $this->fakeUpload();

        $ergebnis = app(MediaSyncService::class)->sync($listing, ListingSnapshot::fromListing($listing), self::SCHEMA_MIETE, 'ent-1');

        self::assertSame(0, $ergebnis->hochgeladen);
        self::assertSame(0, $fake->count('POST', self::REGISTER));
        self::assertContains(sprintf(MediaSyncService::WARNUNG_KEIN_DOKUMENTALBUM, 'Exposé Musterstraße.pdf'), $ergebnis->warnungen);
    }

    public function test_entzogene_freigabe_loescht_das_dokument_in_flowfact(): void
    {
        $listing = $this->listingMitBildUndDokument(true);
        $dokument = $listing->media()->where('typ', MediaTyp::Dokument->value)->first();
        $dokument->update(['flowfact_multimedia_id' => '301', 'flowfact_titel' => 'Exposé']);

        // Freigabe entzogen und neu freigegeben: die Momentaufnahme enthält das Dokument nicht mehr.
        $dokument->update(['freigegeben' => false]);
        $listing = $listing->fresh(['media']);
        $fake = $this->fakeUpload();

        $ergebnis = app(MediaSyncService::class)->sync($listing, ListingSnapshot::fromListing($listing), self::SCHEMA_MIETE, 'ent-1');

        self::assertSame(1, $ergebnis->geloescht);
        self::assertSame(self::BASE.'/multimedia-service/items/301', $fake->requests('DELETE', self::ITEM)[0]->url());
        self::assertNull($dokument->fresh()->flowfact_multimedia_id);
    }

    public function test_energieausweis_wird_wie_ein_dokument_behandelt(): void
    {
        $listing = Listing::factory()->miete()->create();
        $pdfPfad = 'listings/'.$listing->uuid.'/energieausweis.pdf';
        Storage::disk('media')->put($pdfPfad, self::PDF);
        ListingMedia::factory()->energieausweis()->create(['listing_id' => $listing->id, 'pfad' => $pdfPfad, 'freigegeben' => true, 'sortierung' => 0]);
        $listing = $listing->fresh(['media']);
        $fake = $this->fakeUpload();

        $ergebnis = app(MediaSyncService::class)->sync($listing, ListingSnapshot::fromListing($listing), self::SCHEMA_MIETE, 'ent-1');

        self::assertSame(1, $ergebnis->hochgeladen);
        self::assertSame([['albumName' => 'estate_album', 'categories' => ['documents']]], $fake->requests('POST', self::REGISTER)[0]->data()['albumAssignments']);
        self::assertSame(0, $fake->count('PUT', self::ASSIGN), 'Ohne Bilder wird keine Bildreihenfolge gesetzt.');
    }
}
