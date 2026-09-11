<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\TextFeld;
use App\Enums\TextQuelle;
use App\Models\Listing;
use App\Models\ListingText;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TextGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ein_textvorschlag_wird_mit_dem_fake_generator_erzeugt_und_gespeichert(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['titel' => null, 'beschreibung_objekt' => null]);

        $response = $this->actingAs($user)->post(route('app.listings.texts.generate', $listing), [
            'felder' => [TextFeld::Titel->value, TextFeld::BeschreibungObjekt->value],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $this->assertDatabaseHas('listing_texts', [
            'listing_id' => $listing->id,
            'feld' => TextFeld::Titel->value,
            'quelle' => TextQuelle::Ki->value,
            'modell' => 'fake',
            'uebernommen' => false,
        ]);
    }

    public function test_ein_uebernommener_text_wird_in_das_objektfeld_kopiert(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['titel' => null]);

        $vorschlag = ListingText::factory()->ki()->create([
            'listing_id' => $listing->id,
            'feld' => TextFeld::Titel,
            'inhalt' => 'Helle 3-Zimmer-Wohnung in Erkelenz',
            'uebernommen' => false,
        ]);

        $response = $this->actingAs($user)->post(route('app.listings.texts.accept', ['listing' => $listing, 'text' => $vorschlag]));

        $response->assertRedirect();

        $listing->refresh();
        $this->assertSame('Helle 3-Zimmer-Wohnung in Erkelenz', $listing->titel);
        $this->assertTrue($vorschlag->fresh()->uebernommen);
    }
}
