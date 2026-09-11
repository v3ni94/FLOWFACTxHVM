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
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class MediaHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_ein_gueltiges_bild_wird_ueber_das_formular_hochgeladen(): void
    {
        Storage::fake('media');
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($user)->post(route('app.listings.media.store', $listing), [
            'typ' => MediaTyp::Bild->value,
            'dateien' => [UploadedFile::fake()->image('wohnzimmer.jpg', 1000, 700)],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('listing_media', [
            'listing_id' => $listing->id,
            'mime' => 'image/jpeg',
        ]);
    }

    public function test_eine_als_bild_getarnte_textdatei_wird_abgelehnt(): void
    {
        Storage::fake('media');
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $pfad = tempnam(sys_get_temp_dir(), 'flow');
        file_put_contents($pfad, str_repeat('Keine Bilddaten hier.', 30));
        $datei = new UploadedFile($pfad, 'getarnt.jpg', 'image/jpeg', null, true);

        $response = $this->actingAs($user)->post(route('app.listings.media.store', $listing), [
            'typ' => MediaTyp::Bild->value,
            'dateien' => [$datei],
        ]);

        $response->assertSessionHasErrors('dateien');
        $this->assertDatabaseCount('listing_media', 0);
    }

    public function test_eine_dublette_wird_beim_zweiten_upload_abgelehnt(): void
    {
        Storage::fake('media');
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $datei = UploadedFile::fake()->image('doppelt.jpg', 500, 500);

        $this->actingAs($user)->post(route('app.listings.media.store', $listing), [
            'typ' => MediaTyp::Bild->value,
            'dateien' => [$datei],
        ]);

        $response = $this->actingAs($user)->post(route('app.listings.media.store', $listing), [
            'typ' => MediaTyp::Bild->value,
            'dateien' => [UploadedFile::fake()->image('doppelt.jpg', 500, 500)],
        ]);

        $response->assertSessionHasErrors('dateien');
        $this->assertDatabaseCount('listing_media', 1);
    }

    public function test_die_maximale_dateianzahl_wird_serverseitig_durchgesetzt(): void
    {
        Storage::fake('media');
        config(['media.max_files_per_listing' => 2]);

        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $this->actingAs($user)->post(route('app.listings.media.store', $listing), [
            'typ' => MediaTyp::Bild->value,
            'dateien' => [
                UploadedFile::fake()->image('a.jpg', 100, 100),
                UploadedFile::fake()->image('b.jpg', 110, 90),
            ],
        ]);

        $this->assertDatabaseCount('listing_media', 2);

        $response = $this->actingAs($user)->post(route('app.listings.media.store', $listing), [
            'typ' => MediaTyp::Bild->value,
            'dateien' => [UploadedFile::fake()->image('c.jpg', 130, 95)],
        ]);

        $response->assertSessionHasErrors('dateien');
        $this->assertDatabaseCount('listing_media', 2);
    }

    public function test_die_signierte_medienroute_liefert_die_datei_aus(): void
    {
        Storage::fake('media');
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $this->actingAs($user)->post(route('app.listings.media.store', $listing), [
            'typ' => MediaTyp::Bild->value,
            'dateien' => [UploadedFile::fake()->image('signiert.jpg', 400, 400)],
        ]);

        $medium = ListingMedia::query()->where('listing_id', $listing->id)->firstOrFail();

        $url = URL::temporarySignedRoute('app.media.show', now()->addMinutes(10), [
            'media' => $medium->id,
            'variante' => 'original',
        ]);

        $response = $this->actingAs($user)->get($url);

        $response->assertOk();
    }

    public function test_die_medienroute_ohne_gueltige_signatur_liefert_403(): void
    {
        Storage::fake('media');
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $this->actingAs($user)->post(route('app.listings.media.store', $listing), [
            'typ' => MediaTyp::Bild->value,
            'dateien' => [UploadedFile::fake()->image('unsigniert.jpg', 400, 400)],
        ]);

        $medium = ListingMedia::query()->where('listing_id', $listing->id)->firstOrFail();

        $response = $this->actingAs($user)->get('/medien/'.$medium->id.'/original');

        $response->assertForbidden();
    }

    public function test_die_reihenfolge_der_medien_kann_gespeichert_werden(): void
    {
        Storage::fake('media');
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $erstes = ListingMedia::factory()->for($listing)->create(['sortierung' => 0]);
        $zweites = ListingMedia::factory()->for($listing)->create(['sortierung' => 1]);

        $response = $this->actingAs($user)->post(route('app.listings.media.sort', $listing), [
            'reihenfolge' => [
                $erstes->id => 1,
                $zweites->id => 0,
            ],
        ]);

        $response->assertRedirect();
        $this->assertSame(1, $erstes->fresh()->sortierung);
        $this->assertSame(0, $zweites->fresh()->sortierung);
    }
}
