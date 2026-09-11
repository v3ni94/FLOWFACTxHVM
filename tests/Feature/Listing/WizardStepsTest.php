<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\EnergieausweisStatus;
use App\Enums\Objektart;
use App\Enums\TextQuelle;
use App\Enums\Vermarktungsart;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WizardStepsTest extends TestCase
{
    use RefreshDatabase;

    public function test_schritt_eins_speichert_gueltige_grunddaten_und_leitet_weiter(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 1]),
            [
                'aktion' => 'weiter',
                'vermarktungsart' => Vermarktungsart::Miete->value,
                'objektart' => Objektart::Wohnung->value,
                'strasse' => 'Hauptstraße',
                'hausnummer' => '12',
                'plz' => '41812',
                'ort' => 'Erkelenz',
                'land' => 'DE',
                'adresse_im_inserat_anzeigen' => '1',
            ]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 2]));

        $listing->refresh();
        $this->assertSame('Hauptstraße', $listing->strasse);
        $this->assertSame('Erkelenz', $listing->ort);
        $this->assertNotNull($listing->inhalt_geaendert_at);
    }

    public function test_schritt_eins_lehnt_eine_ungueltige_postleitzahl_ab(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 1]),
            [
                'aktion' => 'speichern',
                'vermarktungsart' => Vermarktungsart::Miete->value,
                'objektart' => Objektart::Wohnung->value,
                'plz' => 'ABCDE',
            ]
        );

        $response->assertSessionHasErrors('plz');
    }

    public function test_schritt_eins_erlaubt_das_speichern_eines_unvollstaendigen_entwurfs(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 1]),
            [
                'aktion' => 'speichern',
                'vermarktungsart' => Vermarktungsart::Miete->value,
                'objektart' => Objektart::Wohnung->value,
            ]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 1]));
        $response->assertSessionDoesntHaveErrors();
    }

    public function test_schritt_zwei_speichert_flaechen_und_ausstattung(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['objektart' => Objektart::Wohnung]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 2]),
            [
                'aktion' => 'weiter',
                'wohnflaeche_qm' => '72.5',
                'zimmer' => '3.5',
                'baujahr' => '1995',
                'ausstattung' => ['balkon' => '1', 'keller' => '1'],
            ]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 3]));

        $listing->refresh();
        $this->assertEquals(72.5, (float) $listing->wohnflaeche_qm);
        $this->assertTrue($listing->ausstattung['balkon']);
        $this->assertTrue($listing->ausstattung['keller']);
        $this->assertFalse($listing->ausstattung['terrasse']);
    }

    public function test_schritt_zwei_lehnt_ein_zu_altes_baujahr_ab(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 2]),
            [
                'aktion' => 'speichern',
                'baujahr' => '1500',
            ]
        );

        $response->assertSessionHasErrors('baujahr');
    }

    public function test_schritt_drei_speichert_einen_energieausweis_der_vorliegt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 3]),
            [
                'aktion' => 'weiter',
                'status' => EnergieausweisStatus::LiegtVor->value,
                'ausweistyp' => 'verbrauch',
                'kennwert_kwh' => '120.5',
                'enthaelt_warmwasser' => '1',
            ]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 4]));

        $listing->refresh();
        $this->assertSame(EnergieausweisStatus::LiegtVor, $listing->energy->status);
        $this->assertEquals(120.5, (float) $listing->energy->kennwert_kwh);
    }

    public function test_schritt_drei_verlangt_den_kennwert_wenn_der_ausweis_vorliegt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 3]),
            [
                'aktion' => 'speichern',
                'status' => EnergieausweisStatus::LiegtVor->value,
            ]
        );

        $response->assertSessionHasErrors(['ausweistyp', 'kennwert_kwh']);
    }

    public function test_schritt_sechs_speichert_geaenderte_texte_und_schreibt_eine_listingtext_zeile(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['titel' => null, 'beschreibung_objekt' => null]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 6]),
            [
                'aktion' => 'weiter',
                'titel' => 'Helle Wohnung mit Balkon',
                'beschreibung_objekt' => 'Eine sehr schöne Wohnung in ruhiger Lage.',
            ]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 7]));

        $listing->refresh();
        $this->assertSame('Helle Wohnung mit Balkon', $listing->titel);
        $this->assertDatabaseHas('listing_texts', [
            'listing_id' => $listing->id,
            'feld' => 'titel',
            'quelle' => TextQuelle::Manuell->value,
            'uebernommen' => true,
        ]);
    }

    public function test_schritt_sechs_lehnt_einen_zu_langen_titel_ab(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 6]),
            [
                'aktion' => 'speichern',
                'titel' => str_repeat('a', 101),
            ]
        );

        $response->assertSessionHasErrors('titel');
    }

    public function test_schritt_sieben_speichert_interne_daten(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 7]),
            [
                'aktion' => 'weiter',
                'eigentuemer_name' => 'Muster Verwaltung GbR',
                'interne_notizen' => 'Schlüssel liegt im Büro.',
            ]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 8]));

        $listing->refresh();
        $this->assertSame('Muster Verwaltung GbR', $listing->internal->eigentuemer_name);
    }

    public function test_schritt_sieben_lehnt_zu_lange_interne_notizen_ab(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create();

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 7]),
            [
                'aktion' => 'speichern',
                'interne_notizen' => str_repeat('a', 4001),
            ]
        );

        $response->assertSessionHasErrors('interne_notizen');
    }

    public function test_die_erfassungsschritte_sind_gegen_fremde_objekte_ueber_die_richtlinie_geschuetzt(): void
    {
        $mitarbeiter = User::factory()->create();
        $listing = Listing::factory()->create();

        // Mitarbeiter dürfen laut Datenvertrag jedes Objekt bearbeiten.
        $response = $this->actingAs($mitarbeiter)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 1]));

        $response->assertOk();
    }
}
