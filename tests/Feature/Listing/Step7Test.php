<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\AdressFreigabe;
use App\Enums\Objektart;
use App\Enums\TextQuelle;
use App\Http\Controllers\App\Support\TitleSuggestions;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Schritt 7: Überschrift und interne Bezeichnung (Masterprompt-Abgleich B.1
 * Schritt 7, Masterprompt Abschnitt 15).
 */
final class Step7Test extends TestCase
{
    use RefreshDatabase;

    public function test_die_titelvorschlaege_enthalten_nie_die_strasse_wenn_die_adresse_verborgen_ist(): void
    {
        $listing = Listing::factory()->make([
            'objektart' => Objektart::Wohnung,
            'strasse' => 'Geheimstraße',
            'hausnummer' => '99',
            'ort' => 'Erkelenz',
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
            'zimmer' => 3.0,
            'ausstattung' => ['balkon' => 'ja'],
        ]);

        $vorschlaege = TitleSuggestions::fuer($listing);

        $this->assertNotEmpty($vorschlaege);

        foreach ($vorschlaege as $vorschlag) {
            $this->assertStringNotContainsString('Geheimstraße', $vorschlag);
            $this->assertStringNotContainsString('99', $vorschlag);
        }

        $this->assertTrue(collect($vorschlaege)->contains(fn (string $satz): bool => str_contains($satz, 'Erkelenz')));
        $this->assertTrue(collect($vorschlaege)->contains(fn (string $satz): bool => str_contains($satz, 'Balkon')));
    }

    /**
     * Die Straße wird für Vorschläge grundsätzlich nie gelesen, auch nicht bei
     * vollständiger Adressfreigabe (Masterprompt Abschnitt 15).
     */
    public function test_die_titelvorschlaege_enthalten_den_ort_auch_bei_vollstaendiger_freigabe_aber_nie_die_strasse(): void
    {
        $listing = Listing::factory()->make([
            'objektart' => Objektart::Wohnung,
            'strasse' => 'Musterweg',
            'hausnummer' => '5',
            'ort' => 'Wegberg',
            'adress_freigabe' => AdressFreigabe::Vollstaendig,
            'zimmer' => 2.5,
            'ausstattung' => ['balkon' => 'nein'],
        ]);

        $vorschlaege = TitleSuggestions::fuer($listing);

        $this->assertTrue(collect($vorschlaege)->contains(fn (string $satz): bool => str_contains($satz, 'Wegberg')));

        foreach ($vorschlaege as $vorschlag) {
            $this->assertStringNotContainsString('Musterweg', $vorschlag);
        }
    }

    public function test_die_seite_zeigt_die_titelvorschlaege_als_uebernahmeschaltflaechen(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create([
            'objektart' => Objektart::Wohnung,
            'ort' => 'Erkelenz',
            'zimmer' => 3.0,
        ]);

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 7]));

        $response->assertOk();
        $response->assertSee('Erkelenz');
    }

    public function test_das_speichern_der_ueberschrift_legt_einen_manuellen_text_an(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['titel' => null]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 7]),
            ['titel' => 'Helle Wohnung mit Balkon', 'aktion' => 'weiter']
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 8]));

        $listing->refresh();
        $this->assertSame('Helle Wohnung mit Balkon', $listing->titel);
        $this->assertDatabaseHas('listing_texts', [
            'listing_id' => $listing->id,
            'feld' => 'titel',
            'quelle' => TextQuelle::Manuell->value,
            'inhalt' => 'Helle Wohnung mit Balkon',
            'uebernommen' => true,
        ]);
    }

    public function test_eine_zu_lange_ueberschrift_wird_abgelehnt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 7]),
            ['titel' => str_repeat('a', 101)]
        );

        $response->assertSessionHasErrors('titel');
    }

    public function test_die_interne_bezeichnung_wird_nach_dem_hinterlegten_muster_vorgeschlagen(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create([
            'strasse' => 'Kölner Straße',
            'etage' => 2,
        ]);

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 7]));

        $response->assertOk();
        $response->assertSee($listing->objektnummer.' | Kölner Straße', false);
    }

    public function test_die_technische_referenz_wird_angezeigt_und_ist_nur_lesbar(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 7]));

        $response->assertOk();
        $response->assertSee($listing->objektnummer);
        $response->assertSee($listing->uuid);
        $response->assertDontSee('name="objektnummer"', false);
        $response->assertDontSee('name="uuid"', false);
    }

    public function test_die_autosave_speichert_ein_einzelnes_feld(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['titel' => null, 'interne_bezeichnung' => null]);

        $response = $this->actingAs($user)->patchJson(
            route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 7]),
            ['interne_bezeichnung' => 'MF-Test | Musterweg']
        );

        $response->assertOk();
        $response->assertJsonStructure(['ok', 'gespeichert_at', 'fehlend', 'hinweise']);
        $this->assertSame('MF-Test | Musterweg', $listing->fresh()->interne_bezeichnung);
    }
}
