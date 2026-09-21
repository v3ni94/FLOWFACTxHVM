<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Domain\Settings\SettingsRepository;
use App\Enums\AdressFreigabe;
use App\Enums\Objektart;
use App\Http\Controllers\Admin\FlowfactSettingsController;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Schritte 1 bis 3 des neuen Acht-Schritte-Assistenten (Masterprompt-Abgleich
 * B.1) sowie schrittübergreifende Belange: Berechtigungen, Anzeige aller
 * Objektarten, "Gebäudedaten aus vorhandenem Objekt übernehmen" (Schritt 2).
 */
final class WizardStepsTest extends TestCase
{
    use RefreshDatabase;

    public function test_alle_sechs_schritte_koennen_fuer_jede_objektart_angezeigt_werden(): void
    {
        $user = User::factory()->create();

        foreach (['miete', 'kauf'] as $vermarktungsart) {
            foreach (['', 'gewerbe', 'grundstueck', 'stellplatz', 'mehrfamilienhaus'] as $fabrik) {
                $factory = Listing::factory();
                $factory = $fabrik === '' ? $factory : $factory->{$fabrik}();
                $factory = $vermarktungsart === 'kauf' ? $factory->kauf() : $factory->miete();

                $listing = $factory->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

                foreach (range(1, 6) as $schritt) {
                    $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => $schritt]));
                    self::assertSame(200, $response->getStatusCode(), "Schritt $schritt, Objektart $fabrik, $vermarktungsart");
                }
            }
        }
    }

    public function test_schritt_eins_speichert_gueltige_grunddaten_und_leitet_weiter(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 1]),
            [
                'aktion' => 'weiter',
                'vermarktungsart' => 'miete',
                'objektart' => 'wohnung',
                'bearbeiter_user_id' => $user->id,
                'ansprechpartner_user_id' => $user->id,
                'verfuegbar_ab_typ' => 'sofort',
                'nutzungsstatus' => 'leerstehend',
            ]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 2]));

        $listing->refresh();
        self::assertSame('wohnung', $listing->objektart->value);
        self::assertSame($user->id, $listing->bearbeiter_user_id);
        self::assertNotNull($listing->inhalt_geaendert_at);
    }

    public function test_schritt_eins_verlangt_einen_aktiven_bearbeiter(): void
    {
        $user = User::factory()->create();
        $inaktiv = User::factory()->inactive()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 1]),
            [
                'aktion' => 'speichern',
                'vermarktungsart' => 'miete',
                'objektart' => 'wohnung',
                'bearbeiter_user_id' => $inaktiv->id,
            ]
        );

        $response->assertSessionHasErrors('bearbeiter_user_id');
    }

    public function test_schritt_eins_zeigt_den_hinweis_fuer_nicht_uebertragbare_objektarten(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->stellplatz()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 1]));

        $response->assertOk();
        $response->assertSee('wird lokal erfasst, die Übertragung an FLOWFACT ist noch nicht freigegeben');
    }

    /**
     * Kundenwunsch 21.09.2026: das FLOWFACT-Schema wird je Objekt in Schritt 1
     * aus den im Adminbereich geladenen Schemata gewählt.
     */
    public function test_schritt_eins_zeigt_geladene_schemata_zur_auswahl_und_speichert_die_wahl(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        app(SettingsRepository::class)->set(FlowfactSettingsController::SCHEMATA, [
            ['name' => 'wohnung_miete', 'caption' => 'Wohnung Miete'],
            ['name' => 'haus_kauf', 'caption' => 'Haus Kauf'],
        ]);

        $anzeige = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 1]));

        $anzeige->assertOk();
        $anzeige->assertSee('Wohnung Miete');
        $anzeige->assertSee('Haus Kauf');

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 1]),
            [
                'aktion' => 'weiter',
                'vermarktungsart' => 'miete',
                'objektart' => 'wohnung',
                'flowfact_schema' => 'wohnung_miete',
                'bearbeiter_user_id' => $user->id,
                'ansprechpartner_user_id' => $user->id,
                'verfuegbar_ab_typ' => 'sofort',
                'nutzungsstatus' => 'leerstehend',
            ]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 2]));

        $listing->refresh();
        self::assertSame('wohnung_miete', $listing->flowfact_schema);
    }

    public function test_schritt_eins_ohne_geladene_schemata_zeigt_einen_hinweis(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 1]));

        $response->assertOk();
        $response->assertSee('Schemata laden');
    }

    /**
     * Kundenwunsch 21.09.2026: ein Wechsel der Vermarktungsart in Schritt 1
     * setzt die Adressfreigabe neu, eine bereits in Schritt 2 getroffene Wahl
     * bleibt bei unveränderter Vermarktungsart aber erhalten.
     */
    public function test_ein_wechsel_der_vermarktungsart_in_schritt_eins_setzt_die_adressfreigabe_neu(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->kauf()->create([
            'bearbeiter_user_id' => $user->id,
            'erstellt_von_user_id' => $user->id,
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
        ]);

        $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 1]),
            [
                'aktion' => 'weiter',
                'vermarktungsart' => 'miete',
                'objektart' => 'wohnung',
                'bearbeiter_user_id' => $user->id,
                'verfuegbar_ab_typ' => 'sofort',
            ]
        );

        $this->assertSame(AdressFreigabe::Vollstaendig, $listing->fresh()->adress_freigabe);
    }

    public function test_schritt_zwei_speichert_adresse_und_adressfreigabe(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 2]),
            [
                'aktion' => 'weiter',
                'strasse' => 'Hauptstraße',
                'hausnummer' => '12 bis 14',
                'plz' => '41812',
                'ort' => 'Erkelenz',
                'land' => 'DE',
                'adress_freigabe' => 'nur_plz_ort',
            ]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 3]));

        $listing->refresh();
        self::assertSame('12 bis 14', $listing->hausnummer);
        self::assertSame('nur_plz_ort', $listing->adress_freigabe->value);
        self::assertFalse($listing->adresse_im_inserat_anzeigen);
    }

    public function test_schritt_zwei_lehnt_eine_ungueltige_postleitzahl_ab(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 2]),
            ['aktion' => 'speichern', 'plz' => 'ABCDE']
        );

        $response->assertSessionHasErrors('plz');
    }

    public function test_schritt_zwei_speichert_interne_angaben_getrennt_von_der_adresse(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 2]),
            [
                'aktion' => 'speichern',
                'gebaeudebezeichnung' => 'Haus Am Markt',
                'eigentuemer_name' => 'Muster Verwaltung GbR',
                'interne_notizen' => 'Schlüssel liegt im Büro.',
            ]
        );

        $response->assertSessionDoesntHaveErrors();
        $listing->refresh();
        self::assertSame('Haus Am Markt', $listing->internal->gebaeudebezeichnung);
        self::assertSame('Muster Verwaltung GbR', $listing->internal->eigentuemer_name);
    }

    public function test_gebaeudedaten_uebernehmen_kopiert_nur_leere_felder_und_bleibt_auf_schritt_zwei(): void
    {
        $user = User::factory()->create();
        $quelle = Listing::factory()->create([
            'strasse' => 'Musterweg', 'hausnummer' => '1', 'plz' => '41812', 'ort' => 'Erkelenz',
            'baujahr' => 1990, 'etagen_gesamt' => 5,
        ]);
        $ziel = Listing::factory()->create([
            'strasse' => null, 'hausnummer' => null, 'plz' => '41812', 'ort' => null,
            'baujahr' => null, 'etagen_gesamt' => null,
            'bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $ziel, 'schritt' => 2]),
            ['aktion' => 'uebernehmen', 'quelle_listing_id' => $quelle->id]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $ziel, 'schritt' => 2]));
        $ziel->refresh();
        self::assertSame('Musterweg', $ziel->strasse);
        self::assertSame('1', $ziel->hausnummer);
        self::assertSame(1990, $ziel->baujahr);
        self::assertSame(5, $ziel->etagen_gesamt);
    }

    public function test_gebaeudedaten_uebernehmen_ueberschreibt_nie_vorhandene_werte(): void
    {
        $user = User::factory()->create();
        $quelle = Listing::factory()->create(['strasse' => 'Fremde Straße', 'baujahr' => 1980]);
        $ziel = Listing::factory()->create([
            'strasse' => 'Eigene Straße', 'baujahr' => 2010,
            'bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id,
        ]);

        $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $ziel, 'schritt' => 2]),
            ['aktion' => 'uebernehmen', 'quelle_listing_id' => $quelle->id]
        );

        $ziel->refresh();
        self::assertSame('Eigene Straße', $ziel->strasse);
        self::assertSame(2010, $ziel->baujahr);
    }

    public function test_schritt_drei_speichert_flaechen_mit_deutschem_dezimalformat(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['objektart' => Objektart::Wohnung, 'bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 3]),
            [
                'aktion' => 'weiter',
                'wohnflaeche_qm' => '72,50',
                'zimmer' => '3,5',
                'baujahr' => '1995',
                'zustand' => 'gepflegt',
            ]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 4]));

        $listing->refresh();
        self::assertEquals(72.5, (float) $listing->wohnflaeche_qm);
        self::assertEquals(3.5, (float) $listing->zimmer);
    }

    public function test_schritt_drei_lehnt_ein_zu_altes_baujahr_ab(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 3]),
            ['aktion' => 'speichern', 'baujahr' => '1500']
        );

        $response->assertSessionHasErrors('baujahr');
    }

    public function test_schritt_drei_lehnt_eine_flaeche_von_null_ab_leer_bleibt_aber_unbekannt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['objektart' => Objektart::Wohnung, 'bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $mitNull = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 3]),
            ['aktion' => 'speichern', 'wohnflaeche_qm' => '0']
        );
        $mitNull->assertSessionHasErrors('wohnflaeche_qm');

        $leer = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 3]),
            ['aktion' => 'speichern', 'wohnflaeche_qm' => '']
        );
        $leer->assertSessionDoesntHaveErrors();
        self::assertNull($listing->fresh()->wohnflaeche_qm);
    }

    public function test_leser_kann_get_aber_nicht_post_oder_patch(): void
    {
        $leser = User::factory()->leser()->create();
        $listing = Listing::factory()->create();

        $this->actingAs($leser)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 1]))->assertOk();
        $this->actingAs($leser)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 1]),
            ['aktion' => 'speichern']
        )->assertForbidden();
        $this->actingAs($leser)->patchJson(
            route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 1]),
            []
        )->assertForbidden();
    }

    public function test_mitarbeiter_ohne_zuweisung_bekommt_403_bei_post(): void
    {
        $bearbeiter = User::factory()->create();
        $anderer = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $bearbeiter->id, 'erstellt_von_user_id' => $bearbeiter->id]);

        $this->actingAs($anderer)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 1]),
            ['aktion' => 'speichern']
        )->assertForbidden();
    }
}
