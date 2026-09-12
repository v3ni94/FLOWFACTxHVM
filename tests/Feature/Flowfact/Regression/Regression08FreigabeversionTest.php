<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Enums\ListingStatus;
use App\Enums\MediaTyp;
use App\Enums\PortalStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Flowfact\Sync\ListingSyncService;
use App\Models\ListingMedia;
use App\Models\ListingPortalPublication;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;
use Tests\Feature\Flowfact\Support\FakeFlowfact;

/**
 * Masterprompt Abschnitt 19 und 23, Masterprompt-Abgleich B.6: Übertragung
 * und Veröffentlichung arbeiten ausschließlich mit der jüngsten
 * Freigabeversion. Änderungen am Live-Stand nach der Freigabe erreichen
 * FLOWFACT nicht; Link und Publikation tragen die release_id.
 */
final class Regression08FreigabeversionTest extends FlowfactTestCase
{
    private const string SEARCH = '#^/search-service/schemas/[^/]+$#';

    private const string CREATE = '#^/entity-service/schemas/[^/]+$#';

    private const string GET_ENTITY = '#^/entity-service/schemas/[^/]+/entities/[^/]+$#';

    private const string ITEMS = '#^/multimedia-service/items/entities/[^/]+$#';

    private const string ASSIGN = '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#';

    private const string REGISTER = '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+$#';

    private const string PRESIGNED = '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+/presigned-url$#';

    private const string ITEM = '#^/multimedia-service/items/[^/]+$#';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images', 'dokumente' => 'documents']);
    }

    private function fakeAnlegen(): FakeFlowfact
    {
        return $this->fake()
            ->on('POST', self::SEARCH, self::searchResponse([]))
            ->on('POST', self::CREATE, self::entityResponse('ent-neu'))
            ->on('GET', self::GET_ENTITY, self::entityResponse('ent-neu'))
            ->on('PATCH', self::GET_ENTITY, self::entityResponse('ent-neu'))
            ->on('GET', self::ITEMS, [self::multimediaItem(101)])
            ->on('PUT', self::ASSIGN, ['assignments' => []])
            ->on('GET', self::PRESIGNED, self::presignedResponse())
            ->on('PUT', '#^/flowfact-media/upload/#', fn () => Http::response('', 200))
            ->on('POST', self::REGISTER, fn (Request $request) => Http::response(['multimediaItem' => self::multimediaItem(202, (string) $request->data()['fileName'])]))
            ->on('DELETE', self::ITEM, '')
            ->on('GET', '#^/portal-management-service/portals$#', self::portalsResponse())
            ->on('POST', '#^/portal-management-service/publish$#', fn () => Http::response('', 200))
            ->install();
    }

    public function test_payload_stammt_aus_der_freigabe_und_ignoriert_spaetere_live_aenderungen(): void
    {
        $listing = $this->bereitesListing(['titel' => 'Titel zur Freigabe', 'baujahr' => 1998]);
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $release = $this->freigeben($listing, []);

        // Live-Änderungen nach der Freigabe: Titel, Baujahr, Bildtitel.
        $listing->update(['titel' => 'Live geändert, nicht freigegeben', 'baujahr' => 2001]);
        $listing->media()->update(['titel' => 'Live-Bildtitel']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $fake = $this->fakeAnlegen();

        $ergebnis = app(ListingSyncService::class)->sync($listing);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame($release->id, $ergebnis->releaseId);

        $body = $fake->requests('POST', self::CREATE)[0]->data();
        self::assertSame(['values' => ['Titel zur Freigabe']], $body['headline']);
        self::assertSame(['values' => [1998]], $body['yearofconstruction']);
        self::assertStringNotContainsString('Live geändert', json_encode($body, JSON_THROW_ON_ERROR));
        self::assertSame(0, $fake->count('PATCH', self::ITEM), 'Der Live-Bildtitel ist nicht freigegeben und wird nicht übertragen.');

        $link = $listing->flowfactLink()->first();
        self::assertSame($release->id, $link->release_id);
        self::assertSame($release->inhalt_hash, $link->uebertragener_inhalt_hash);
        self::assertSame(SyncStatus::Uebertragen, $link->sync_status);
        self::assertTrue($listing->fresh()->hatUnveroeffentlichteAenderungen(), 'Die Oberfläche zeigt weiterhin unveröffentlichte Änderungen.');
    }

    public function test_medien_kommen_aus_der_freigabe_mit_titel_und_drehung(): void
    {
        $listing = $this->bereitesListing();
        $medium = $listing->media()->first();
        Storage::disk('media')->put($medium->pfad, $this->beispielbild(40, 30));
        $medium->update(['titel' => 'Freigegebener Titel', 'rotation' => 90]);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $this->freigeben($listing, []);

        // Nach der Freigabe: Bildtitel geändert und ein zweites Bild hinzugefügt.
        // Beides wirkt erst mit der nächsten Freigabe.
        $medium->update(['titel' => 'Später geändert']);
        $zweitesPfad = 'listings/'.$listing->uuid.'/zweites.jpg';
        Storage::disk('media')->put($zweitesPfad, $this->beispielbild(50, 50));
        ListingMedia::factory()->create(['listing_id' => $listing->id, 'typ' => MediaTyp::Bild, 'pfad' => $zweitesPfad, 'sortierung' => 1]);
        $fake = $this->fakeAnlegen();

        $ergebnis = app(ListingSyncService::class)->sync($listing->fresh(['price', 'energy', 'media']));

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('POST', self::REGISTER), 'Nur das freigegebene Bild wird hochgeladen, das spätere nicht.');
        $register = $fake->requests('POST', self::REGISTER)[0]->data();
        self::assertSame('Freigegebener Titel', $register['title']);
        self::assertStringContainsString('-r90.jpg', $register['fileName'], 'Die Drehung ist Teil des Dateinamens.');

        $upload = $fake->requests('PUT', '#^/flowfact-media/upload/#')[0];
        $info = getimagesizefromstring($upload->body());
        self::assertSame([30, 40], [$info[0], $info[1]], 'Die Drehung um 90 Grad ist eingebrannt.');
    }

    public function test_publikation_und_link_tragen_die_release_id(): void
    {
        $listing = $this->bereitesListing();
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $release = $this->freigeben($listing, ['portal-is24']);
        $this->fakeAnlegen();

        $ergebnis = app(FlowfactPublishingService::class)->publish($listing, ['portal-is24']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        $publication = ListingPortalPublication::query()->first();
        self::assertSame($release->id, $publication->release_id);
        self::assertSame(PortalStatus::Angefordert, $publication->status);
        self::assertSame($release->id, $listing->flowfactLink()->first()->release_id);
        self::assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status);
    }
}
