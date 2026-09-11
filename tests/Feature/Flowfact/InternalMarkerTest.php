<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Enums\ListingStatus;
use App\Flowfact\Sync\PublishingService;
use App\Models\Listing;
use App\Models\ListingInternal;
use App\Models\ListingMedia;
use App\Models\TransferLog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * ADR-003: Interne Felder gelangen technisch nie in die Übertragung. Alle
 * internen Felder tragen einen Markerwert, danach werden Übertragung und
 * Veröffentlichung vollständig durchlaufen.
 */
final class InternalMarkerTest extends FlowfactTestCase
{
    private const string MARKER = 'INTERN-MARKER-XYZ';

    public function test_der_marker_erscheint_in_keiner_anfrage_und_in_keinem_protokolleintrag(): void
    {
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');

        $listing = Listing::factory()->miete()->vollstaendig()->create(['status' => ListingStatus::Bereit]);
        ListingInternal::factory()->create([
            'listing_id' => $listing->id,
            'eigentuemer_name' => self::MARKER.' Eigentümer',
            'eigentuemer_kontakt' => self::MARKER.' Kontakt',
            'verwaltungsobjekt_referenz' => self::MARKER.' Referenz',
            'interne_notizen' => self::MARKER.' Notizen',
            'schluessel_hinweis' => self::MARKER.' Schlüssel',
            'besichtigung_intern' => self::MARKER.' Besichtigung',
            'kalkulation_notiz' => self::MARKER.' Kalkulation',
        ]);

        /** @var ListingMedia $medium */
        $medium = $listing->media()->first();
        Storage::disk('media')->put($medium->pfad, $this->beispielbild());

        $this->fake()
            ->on('POST', '#^/search-service/schemas/[^/]+$#', self::searchResponse([]))
            ->on('POST', '#^/entity-service/schemas/[^/]+$#', self::entityResponse('ent-1'))
            ->on('GET', '#^/multimedia-service/albums/schemas/[^/]+$#', self::albumsResponse())
            ->on('GET', '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+/presigned-url$#', self::presignedResponse())
            ->on('PUT', '#^/flowfact-media/upload/#', fn () => Http::response('', 200))
            ->on('POST', '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+$#', ['multimediaItem' => self::multimediaItem(101)])
            ->on('GET', '#^/multimedia-service/items/entities/[^/]+$#', [self::multimediaItem(101)])
            ->on('PUT', '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#', ['assignments' => []])
            ->on('GET', '#^/portal-management-service/portals$#', self::portalsResponse())
            ->on('POST', '#^/portal-management-service/publish$#', self::publishResponse('ent-1', transferiert: ['portal-is24']))
            ->on('GET', '#^/portal-management-service/estates/[^/]+/portals$#', self::estatePortalsResponse(['portal-is24']))
            ->install();

        $service = app(PublishingService::class);
        $ergebnis = $service->publish($listing->fresh(['price', 'energy', 'media', 'internal']), ['portal-is24']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);

        $anfragen = Http::recorded();
        self::assertGreaterThanOrEqual(9, count($anfragen));

        foreach ($anfragen as [$request]) {
            /** @var Request $request */
            self::assertStringNotContainsString(self::MARKER, $request->body());
            self::assertStringNotContainsString(self::MARKER, $request->url());
            self::assertStringNotContainsString(self::MARKER, json_encode($request->headers(), JSON_THROW_ON_ERROR));
        }

        self::assertGreaterThanOrEqual(9, TransferLog::query()->count());

        foreach (TransferLog::query()->get() as $log) {
            self::assertStringNotContainsString(self::MARKER, json_encode($log->getAttributes(), JSON_THROW_ON_ERROR));
        }

        self::assertStringNotContainsString(self::MARKER, json_encode($ergebnis->warnungen, JSON_THROW_ON_ERROR));
    }
}
