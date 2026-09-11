<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Enums\MediaTyp;
use App\Flowfact\Sync\MediaSyncService;
use App\Flowfact\Sync\SyncLease;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\ListingMedia;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;

/**
 * Prüfbericht 2026-09-11, Befund 7: release() gibt nur die eigene Lease frei
 * (Token), der Herzschlag verlängert nur die eigene Lease, und der
 * Medienabgleich ruft den Herzschlag nach jedem übertragenen Bild.
 */
final class Regression07LeaseTest extends FlowfactTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_release_des_ersten_laufs_laesst_die_lease_des_zweiten_bestehen(): void
    {
        $listing = Listing::factory()->create();
        $link = ListingFlowfactLink::factory()->create(['listing_id' => $listing->id]);
        $lease = new SyncLease(3);

        $laufA = $link->fresh();
        $laufB = $link->fresh();
        $laufC = $link->fresh();

        Carbon::setTestNow('2026-09-11 10:00:00');
        $tokenA = $lease->acquire($laufA);
        self::assertNotNull($tokenA, 'Lauf A startet.');
        self::assertSame($tokenA, $link->fresh()->sperre_token);

        // Lauf A überschreitet die Lease, Lauf B übernimmt die abgelaufene Lease.
        Carbon::setTestNow('2026-09-11 10:03:01');
        $tokenB = $lease->acquire($laufB);
        self::assertNotNull($tokenB, 'Lauf B übernimmt die abgelaufene Lease.');
        self::assertNotSame($tokenA, $tokenB);

        // Lauf A endet, finally-Block: release() mit dem eigenen Token.
        $lease->release($laufA, $tokenA);
        $aktuell = $link->fresh();
        self::assertNotNull($aktuell->sperre_bis, 'Die Lease von B bleibt bestehen.');
        self::assertSame($tokenB, $aktuell->sperre_token);

        // Ein dritter Lauf wird blockiert, solange B läuft.
        self::assertNull($lease->acquire($laufC), 'Lauf C darf nicht parallel zu B starten.');

        // B gibt seine Lease frei, danach ist der Weg wieder frei.
        $lease->release($laufB, $tokenB);
        self::assertNull($link->fresh()->sperre_bis);
        self::assertNull($link->fresh()->sperre_token);
        self::assertNotNull($lease->acquire($laufC));
    }

    public function test_herzschlag_verlaengert_nur_die_eigene_lease(): void
    {
        $listing = Listing::factory()->create();
        $link = ListingFlowfactLink::factory()->create(['listing_id' => $listing->id]);
        $lease = new SyncLease(3);

        Carbon::setTestNow('2026-09-11 10:00:00');
        $tokenA = $lease->acquire($link->fresh());

        Carbon::setTestNow('2026-09-11 10:01:00');
        self::assertTrue($lease->extend($link->fresh(), $tokenA));
        self::assertTrue($link->fresh()->sperre_bis->equalTo(Carbon::parse('2026-09-11 10:04:00')), 'Die eigene Lease wird um die volle Laufzeit verlängert.');

        Carbon::setTestNow('2026-09-11 10:04:01');
        $tokenB = $lease->acquire($link->fresh());
        self::assertNotNull($tokenB);

        Carbon::setTestNow('2026-09-11 10:05:00');
        self::assertFalse($lease->extend($link->fresh(), $tokenA), 'Der überholte Lauf kann die fremde Lease nicht verlängern.');
        self::assertTrue($link->fresh()->sperre_bis->equalTo(Carbon::parse('2026-09-11 10:07:01')), 'Die Lease von B bleibt unverändert.');
    }

    public function test_medienabgleich_ruft_den_herzschlag_nach_jedem_uebertragenen_bild(): void
    {
        $this->hinterlegeToken();
        Storage::fake('media');
        $this->settings()->set('flowfact.album_'.self::SCHEMA_MIETE, ['album' => 'estate_album', 'bilder' => 'images']);

        $listing = Listing::factory()->miete()->create();

        for ($i = 0; $i < 3; $i++) {
            $pfad = 'listings/'.$listing->uuid.'/bild-'.$i.'.jpg';
            Storage::disk('media')->put($pfad, $this->beispielbild(40 + $i, 30));
            ListingMedia::factory()->create(['listing_id' => $listing->id, 'typ' => MediaTyp::Bild, 'pfad' => $pfad, 'sortierung' => $i]);
        }

        $naechsteId = 500;
        $this->fake()
            ->on('GET', '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+/presigned-url$#', self::presignedResponse())
            ->on('PUT', '#^/flowfact-media/upload/#', fn () => Http::response('', 200))
            ->on('POST', '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+$#', fn (Request $request) => Http::response(['multimediaItem' => self::multimediaItem(++$naechsteId, (string) $request->data()['fileName'])]))
            ->on('GET', '#^/multimedia-service/items/entities/[^/]+$#', [])
            ->on('PUT', '#^/multimedia-service/assigned/schemas/[^/]+/entities/[^/]+$#', ['assignments' => []])
            ->install();

        $herzschlaege = 0;
        $ergebnis = app(MediaSyncService::class)->sync(
            $listing->fresh(['media']),
            self::SCHEMA_MIETE,
            'ent-1',
            null,
            null,
            false,
            function () use (&$herzschlaege): void {
                $herzschlaege++;
            },
        );

        self::assertSame(3, $ergebnis->hochgeladen);
        self::assertSame(3, $herzschlaege, 'Nach jedem übertragenen Bild wird die Lease verlängert.');
    }
}
