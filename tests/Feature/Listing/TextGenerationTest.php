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
        $this->assertNotNull($vorschlag->fresh()->datenbasis_hash);
    }

    /**
     * Ohne konfigurierten KI-Anbieter ist der FakeTextGenerator aktiv
     * (phpunit.xml, AI_PROVIDER=fake): die Vorschläge sind ehrlich als
     * "Vorlagenentwurf (ohne KI)" gekennzeichnet, nie als KI-Erzeugnis
     * getarnt (Masterprompt Abschnitt 16).
     */
    public function test_die_texterzeugung_kennzeichnet_vorlagenentwuerfe_ohne_ki_ehrlich(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['beschreibung_objekt' => null]);

        $response = $this->actingAs($user)->post(route('app.listings.texts.generate', $listing), [
            'felder' => [TextFeld::BeschreibungObjekt->value],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertStringContainsString('Modell: fake', session('status'));

        $listing->refresh();
        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 8]));
        $response->assertOk();
        $response->assertSee('Vorlagenentwurf (ohne KI)');
    }

    public function test_eine_ueberarbeitung_speichert_einen_neuen_vorschlag_und_ueberschreibt_den_aktuellen_text_nicht(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create([
            'beschreibung_objekt' => 'Diese Wohnung ist wirklich traumhaft schön und einzigartig!',
        ]);

        $response = $this->actingAs($user)->post(route('app.listings.texts.revise', $listing), [
            'feld' => TextFeld::BeschreibungObjekt->value,
            'text' => $listing->beschreibung_objekt,
            'anweisung' => 'sachlicher',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        $listing->refresh();
        // Der aktuelle Text bleibt unverändert, bis der Vorschlag ausdrücklich
        // übernommen wird (Masterprompt Abschnitt 16).
        $this->assertSame('Diese Wohnung ist wirklich traumhaft schön und einzigartig!', $listing->beschreibung_objekt);

        $this->assertDatabaseHas('listing_texts', [
            'listing_id' => $listing->id,
            'feld' => TextFeld::BeschreibungObjekt->value,
            'quelle' => TextQuelle::Ki->value,
            'anweisung' => 'sachlicher',
            'uebernommen' => false,
        ]);
    }
}
