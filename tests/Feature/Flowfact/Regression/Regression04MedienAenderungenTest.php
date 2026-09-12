<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\ListingStatus;
use App\Enums\MediaTyp;
use App\Enums\SyncStatus;
use App\Flowfact\Sync\ListingSyncService;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\ListingMedia;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;
use Tests\Feature\Flowfact\Support\FakeFlowfact;

/**
 * Prüfbericht 2026-09-11, Befund 4: Nach dem Erstupload werden Ausblenden
 * (im_inserat = false), Reihenfolge, Titelbild und Bildtitel an FLOWFACT
 * nachgezogen. Seit Welle 3 gilt dafür die jüngste Freigabeversion (B.6):
 * jede Änderung wird vor der Übertragung erneut freigegeben.
 */
final class Regression04MedienAenderungenTest extends FlowfactTestCase
{
    private const string GET_ENTITY = '#^/entity-service/schemas/[^/]+/entities/[^/]+$#';

    private const string ITEMS = '#^/multimedia-service/items/entities/[^/]+$#';

    private const string ASSIGN = '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#';

    private const string ITEM = '#^/multimedia-service/items/[^/]+$#';

    private const string ALBUMS = '#^/multimedia-service/albums/schemas/[^/]+$#';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
    }

    private function uebertragenesListingMitZweiBildern(): Listing
    {
        $listing = $this->bereitesListing(['status' => ListingStatus::Veroeffentlicht]);
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild']);
        ListingMedia::factory()->create([
            'listing_id' => $listing->id,
            'typ' => MediaTyp::Bild,
            'dateiname_original' => 'kueche.jpg',
            'sortierung' => 1,
            'flowfact_multimedia_id' => '102',
        ]);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $release = $this->freigeben($listing, []);

        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id,
            'flowfact_entity_id' => 'ent-1',
            'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen,
            'uebertragener_inhalt_hash' => app(ListingContentHasher::class)->hash($listing),
            'release_id' => $release->id,
        ]);

        return $listing;
    }

    private function fakeAktualisierung(): FakeFlowfact
    {
        return $this->fake()
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-1'))
            ->on('PATCH', self::GET_ENTITY, fn () => Http::response('', 200))
            ->on('GET', self::ALBUMS, self::albumsResponse())
            ->on('GET', self::ITEMS, [self::multimediaItem(101), self::multimediaItem(102)])
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->on('DELETE', self::ITEM, '')
            ->on('PATCH', self::ITEM, fn (Request $request) => Http::response(['multimediaItem' => self::multimediaItem(102)]))
            ->install();
    }

    public function test_ausgeblendetes_bild_wird_in_flowfact_geloescht_und_die_reihenfolge_neu_gesetzt(): void
    {
        $listing = $this->uebertragenesListingMitZweiBildern();

        // Benutzer nimmt das zweite Bild aus dem Inserat (Schritt 5, "Im Inserat" abgehakt).
        $listing->media()->where('flowfact_multimedia_id', '102')->update(['im_inserat' => false]);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);
        $fake = $this->fakeAktualisierung();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(SyncStatus::Uebertragen, $listing->flowfactLink()->first()->sync_status);
        self::assertSame(1, $fake->count('DELETE', self::ITEM), 'Item 102 wird in FLOWFACT gelöscht.');
        self::assertSame(self::BASE.'/multimedia-service/items/102', $fake->requests('DELETE', self::ITEM)[0]->url());
        self::assertNull($listing->media()->where('dateiname_original', 'kueche.jpg')->first()->flowfact_multimedia_id, 'Lokale ID wird geleert.');

        self::assertSame(1, $fake->count('PUT', self::ASSIGN), 'Die Zuordnung wird neu gesetzt.');
        $eintraege = $fake->requests('PUT', self::ASSIGN)[0]->data()['assignments']['images'];
        self::assertCount(1, $eintraege);
        self::assertSame(101, $eintraege[0]['multimedia']['id']);
    }

    public function test_neue_reihenfolge_titelbild_und_bildtitel_werden_uebertragen(): void
    {
        $listing = $this->uebertragenesListingMitZweiBildern();

        // Titelbild tauschen und dem neuen Titelbild einen Titel geben (Schritt 5).
        $listing->media()->where('flowfact_multimedia_id', '101')->update(['sortierung' => 1]);
        $listing->media()->where('flowfact_multimedia_id', '102')->update(['sortierung' => 0, 'titel' => 'Neuer Titel']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);
        $fake = $this->fakeAktualisierung();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('PATCH', self::GET_ENTITY), 'Hash hat sich geändert, PATCH wird gesendet.');

        self::assertSame(1, $fake->count('PUT', self::ASSIGN), 'Reihenfolge und Titelbild werden übertragen.');
        $eintraege = $fake->requests('PUT', self::ASSIGN)[0]->data()['assignments']['images'];
        self::assertSame([102, 101], [$eintraege[0]['multimedia']['id'], $eintraege[1]['multimedia']['id']]);
        self::assertSame([0, 1], [$eintraege[0]['sorting'], $eintraege[1]['sorting']]);

        self::assertSame(1, $fake->count('PATCH', self::ITEM), 'Nur der geänderte Titel wird per JSON-Patch gesendet.');
        $patch = $fake->requests('PATCH', self::ITEM)[0];
        self::assertSame(self::BASE.'/multimedia-service/items/102', $patch->url());
        self::assertSame([['op' => 'replace', 'path' => '/title', 'value' => 'Neuer Titel']], $patch->data());
        self::assertSame('Neuer Titel', $listing->media()->where('flowfact_multimedia_id', '102')->first()->flowfact_titel);
        self::assertSame(SyncStatus::Uebertragen, $listing->flowfactLink()->first()->sync_status);
    }

    public function test_dokument_mit_flowfact_item_wird_geloescht(): void
    {
        $listing = $this->uebertragenesListingMitZweiBildern();
        ListingMedia::factory()->create([
            'listing_id' => $listing->id,
            'typ' => MediaTyp::Dokument,
            'mime' => 'application/pdf',
            'dateiname_original' => 'expose.pdf',
            'sortierung' => 5,
            'flowfact_multimedia_id' => '103',
        ]);
        $listing->update(['titel' => 'Geänderter Titel']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);
        $fake = $this->fakeAktualisierung();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(self::BASE.'/multimedia-service/items/103', $fake->requests('DELETE', self::ITEM)[0]->url());
        self::assertNull($listing->media()->where('typ', MediaTyp::Dokument->value)->first()->flowfact_multimedia_id);
    }
}
