<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Listing\ListingContentHasher;
use App\Domain\Listing\ReleaseService;
use App\Enums\ReleaseAktion;
use App\Models\Listing;
use App\Models\ListingChange;
use App\Models\ListingMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Freigabeversionen (Masterprompt-Abgleich B.6).
 */
final class ReleaseServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_freigeben_erzeugt_fortlaufende_versionen_mit_eingefrorenem_inhalt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create();
        ListingMedia::factory()->dokument()->create(['listing_id' => $listing->id, 'sortierung' => 1]);
        $service = app(ReleaseService::class);

        $erste = $service->freigeben($listing, $user, ReleaseAktion::FlowfactSpeichern, []);

        self::assertSame(1, $erste->version);
        self::assertSame(ReleaseAktion::FlowfactSpeichern, $erste->aktion);
        self::assertSame([], $erste->portale_json);
        self::assertTrue($erste->freigegebenVon->is($user));
        self::assertNotNull($erste->freigegeben_at);
        self::assertSame($listing->titel, $erste->payload_json['listing']['titel']);
        self::assertSame(80_000, $erste->payload_json['price']['kaltmiete_cent']);
        self::assertArrayNotHasKey('medien', $erste->payload_json);
        self::assertCount(1, $erste->medien_json, 'Nur das freigegebene Titelbild, nicht das Dokument.');
        self::assertSame('bild', $erste->medien_json[0]['typ']);
        self::assertSame(app(ListingContentHasher::class)->hash($listing->fresh(['price', 'energy', 'media'])), $erste->inhalt_hash);

        $zweite = $service->freigeben($listing, $user, ReleaseAktion::Veroeffentlichen, ['portal-is24', 'portal-immowelt']);

        self::assertSame(2, $zweite->version);
        self::assertSame(['portal-is24', 'portal-immowelt'], $zweite->portale_json);
        self::assertSame($erste->inhalt_hash, $zweite->inhalt_hash, 'Unveränderter Inhalt ergibt denselben Hash.');
        self::assertTrue($service->latest($listing)->is($zweite));
        self::assertTrue($listing->fresh()->latestRelease->is($zweite));
        self::assertSame([2, 1], $listing->releases()->pluck('version')->all());
        self::assertSame($zweite->payload_json, $zweite->snapshot()->payload());

        $eintraege = ListingChange::query()->where('feld', 'listing_releases.version')->orderBy('id')->get();
        self::assertCount(2, $eintraege);
        self::assertNull($eintraege[0]->alt);
        self::assertSame('1 (flowfact_speichern)', $eintraege[0]->neu);
        self::assertSame('1', $eintraege[1]->alt);
        self::assertSame($user->id, $eintraege[1]->user_id);
    }

    public function test_ist_aktuell_und_unveroeffentlichte_aenderungen_folgen_dem_hash(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create();
        $service = app(ReleaseService::class);

        self::assertFalse($listing->hatUnveroeffentlichteAenderungen(), 'Ohne Freigabeversion gibt es keinen Vergleichsstand.');

        $release = $service->freigeben($listing, $user, ReleaseAktion::Veroeffentlichen, ['portal-is24']);

        self::assertTrue($service->istAktuell($release));
        self::assertFalse($listing->fresh(['price', 'energy', 'media'])->hatUnveroeffentlichteAenderungen());

        // Interne Änderung: keine unveröffentlichte Änderung.
        $listing->internal()->create(['interne_notizen' => 'Nur intern']);
        self::assertTrue($service->istAktuell($release->fresh()));

        // Inseratsänderung: Version ist nicht mehr aktuell.
        $listing->update(['titel' => 'Neuer Titel für das Inserat']);

        self::assertFalse($service->istAktuell($release->fresh()));
        self::assertTrue($listing->fresh(['price', 'energy', 'media'])->hatUnveroeffentlichteAenderungen());

        // Erneute Freigabe friert den neuen Stand ein.
        $neu = $service->freigeben($listing->fresh(), $user, ReleaseAktion::Veroeffentlichen, ['portal-is24']);
        self::assertSame(3, $neu->version + 1);
        self::assertNotSame($release->inhalt_hash, $neu->inhalt_hash);
        self::assertFalse($listing->fresh(['price', 'energy', 'media'])->hatUnveroeffentlichteAenderungen());
        self::assertFalse($service->istAktuell($release->fresh()), 'Die ältere Version bleibt veraltet.');
    }

    public function test_die_freigabe_eines_medientitels_aendert_den_hash(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create();
        $service = app(ReleaseService::class);

        $release = $service->freigeben($listing, $user, ReleaseAktion::Veroeffentlichen, ['portal-is24']);

        $listing->media()->first()->update(['rotation' => 180]);
        self::assertFalse($service->istAktuell($release->fresh()), 'Die Drehung eines freigegebenen Bildes ist Teil des Inhalts.');
    }
}
