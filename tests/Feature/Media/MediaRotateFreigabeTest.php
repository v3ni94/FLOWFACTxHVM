<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Enums\MediaTyp;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class MediaRotateFreigabeTest extends TestCase
{
    use RefreshDatabase;

    public function test_heic_datei_wird_mit_eigener_meldung_abgelehnt(): void
    {
        Storage::fake('media');
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $datei = UploadedFile::fake()->create('foto.heic', 500, 'image/heic');

        $response = $this->actingAs($user)->post(route('app.listings.media.store', $listing), [
            'typ' => MediaTyp::Bild->value,
            'dateien' => [$datei],
        ]);

        $response->assertSessionHasErrors('dateien');
        self::assertStringContainsString('HEIC wird derzeit nicht unterstützt', session('errors')->first('dateien'));
        $this->assertDatabaseCount('listing_media', 0);
    }

    public function test_dokumente_und_energieausweise_sind_standardmaessig_nicht_freigegeben_bilder_schon(): void
    {
        $bild = ListingMedia::factory()->bild()->create();
        $dokument = ListingMedia::factory()->dokument()->create();
        $energieausweis = ListingMedia::factory()->energieausweis()->create();

        self::assertTrue($bild->freigegeben);
        self::assertFalse($dokument->freigegeben);
        self::assertFalse($energieausweis->freigegeben);
    }

    public function test_ein_medium_kann_gedreht_werden(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();
        $medium = ListingMedia::factory()->for($listing)->create(['rotation' => 0]);

        $response = $this->actingAs($user)->post(route('app.listings.media.rotate', ['listing' => $listing, 'media' => $medium]));
        $response->assertRedirect();
        self::assertSame(90, $medium->fresh()->rotation);

        $this->actingAs($user)->post(route('app.listings.media.rotate', ['listing' => $listing, 'media' => $medium]), ['richtung' => 'links']);
        self::assertSame(0, $medium->fresh()->rotation);

        $this->actingAs($user)->post(route('app.listings.media.rotate', ['listing' => $listing, 'media' => $medium]), ['richtung' => 'links']);
        self::assertSame(270, $medium->fresh()->rotation);
    }

    public function test_die_freigabe_eines_dokuments_kann_umgeschaltet_werden(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();
        $medium = ListingMedia::factory()->for($listing)->dokument()->create(['freigegeben' => false]);

        $response = $this->actingAs($user)->post(
            route('app.listings.media.update', ['listing' => $listing, 'media' => $medium]),
            ['freigegeben' => '1']
        );

        $response->assertRedirect();
        self::assertTrue($medium->fresh()->freigegeben);
    }
}
