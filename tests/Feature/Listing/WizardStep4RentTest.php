<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\ProvisionTyp;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Schritt 4 (Preise und Heizung, Masterprompt-Abgleich B.1) über den neuen
 * Assistenten: Kostenstruktur der Heizkosten statt der bisherigen getrennten
 * Felder heizkosten_versorgung / heizkosten_in_nebenkosten_enthalten (siehe
 * App\Enums\HeizkostenStruktur), Stellplatzmodus, Provision.
 */
final class WizardStep4RentTest extends TestCase
{
    use RefreshDatabase;

    private function postSchritt4(User $user, Listing $listing, array $daten): TestResponse
    {
        return $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 4]),
            array_merge([
                'aktion' => 'speichern',
                'provision_typ' => 'provisionsfrei',
                'stellplatz_modus' => 'keiner',
            ], $daten)
        );
    }

    public function test_fall_a_zentral_zusaetzlich_addiert_die_heizkosten(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => '800,00',
            'nebenkosten' => '200,00',
            'heizkosten' => '100,00',
            'heizkosten_struktur' => 'zusaetzlich',
        ]);

        $response->assertSessionDoesntHaveErrors();
        self::assertSame(110_000, $listing->price->fresh()->warmmiete_cent);
        self::assertSame('zentral', $listing->fresh()->heizkosten_versorgung->value);
    }

    public function test_fall_b_enthalten_addiert_die_heizkosten_nicht_erneut(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => '800,00',
            'nebenkosten' => '300,00',
            'heizkosten' => '100,00',
            'heizkosten_struktur' => 'enthalten',
        ]);

        $response->assertSessionDoesntHaveErrors();
        self::assertSame(110_000, $listing->price->fresh()->warmmiete_cent);
    }

    public function test_fall_d_eigener_vertrag_erzeugt_den_versorgerhinweis_und_verwirft_heizkosten(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => '800,00',
            'nebenkosten' => '150,00',
            'heizkosten_struktur' => 'eigener_vertrag',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $listing->refresh();
        self::assertSame(95_000, $listing->price->fresh()->warmmiete_cent);
        self::assertSame('dezentral', $listing->heizkosten_versorgung->value);
    }

    /**
     * Abweichung von Datenvertrag Abschnitt 3, Fall E (bewusst,
     * Masterprompt-Abgleich B.1/B.2): die drei Heizkosten-Kacheln lassen ein
     * Heizkostenfeld bei "eigener Versorgungsvertrag" gar nicht mehr zu, statt
     * einen Formularfehler zu erzeugen, verwirft PriceStructure::apply()
     * versehentlich mitgesendete Heizkosten. Ein Widerspruch kann über dieses
     * Formular nicht mehr entstehen.
     */
    public function test_fall_e_heizkosten_werden_bei_eigenem_vertrag_verworfen_statt_abgelehnt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => '800,00',
            'nebenkosten' => '150,00',
            'heizkosten' => '50,00',
            'heizkosten_struktur' => 'eigener_vertrag',
        ]);

        $response->assertSessionDoesntHaveErrors();
        self::assertNull($listing->price->fresh()->heizkosten_cent);
        self::assertSame(95_000, $listing->price->fresh()->warmmiete_cent);
    }

    public function test_fall_f_heizkosten_groesser_als_nebenkosten_wird_abgelehnt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => '800,00',
            'nebenkosten' => '200,00',
            'heizkosten' => '250,00',
            'heizkosten_struktur' => 'enthalten',
        ]);

        $response->assertSessionHasErrors('heizkosten');
    }

    public function test_ein_ungueltiges_betragsformat_wird_abgelehnt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => 'nicht-numerisch',
            'nebenkosten' => '200,00',
            'heizkosten_struktur' => 'zusaetzlich',
        ]);

        $response->assertSessionHasErrors('kaltmiete');
        self::assertStringContainsString('1.234,56', session('errors')->first('kaltmiete'));
    }

    public function test_stellplatz_optional_verlangt_einen_betrag(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => '800,00',
            'nebenkosten' => '200,00',
            'heizkosten_struktur' => 'zusaetzlich',
            'stellplatz_modus' => 'optional',
        ]);

        // Ohne Betrag ist das kein Formularfehler (Entwurf darf unvollständig
        // bleiben), CompletenessCheck blockiert erst die Veröffentlichung.
        $response->assertSessionDoesntHaveErrors();
        self::assertSame('optional', $listing->price->fresh()->stellplatz_modus->value);

        $mitBetrag = $this->postSchritt4($user, $listing, [
            'kaltmiete' => '800,00',
            'nebenkosten' => '200,00',
            'heizkosten_struktur' => 'zusaetzlich',
            'stellplatz_modus' => 'optional',
            'stellplatz_miete' => '50,00',
        ]);
        $mitBetrag->assertSessionDoesntHaveErrors();
        self::assertSame(5_000, $listing->price->fresh()->stellplatz_miete_cent);
    }

    public function test_stellplatz_pflicht_enthalten_lehnt_einen_eigenen_betrag_ab(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => '800,00',
            'nebenkosten' => '200,00',
            'heizkosten_struktur' => 'zusaetzlich',
            'stellplatz_modus' => 'pflicht_enthalten',
            'stellplatz_miete' => '50,00',
        ]);

        $response->assertSessionHasErrors('heizkosten');
    }

    public function test_provision_pflichtig_kann_ohne_bestaetigung_als_entwurf_gespeichert_werden(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 4]),
            [
                'aktion' => 'speichern',
                'kaltmiete' => '800,00',
                'nebenkosten' => '200,00',
                'heizkosten_struktur' => 'zusaetzlich',
                'stellplatz_modus' => 'keiner',
                'provision_typ' => 'provisionspflichtig',
                'provision_text' => '3,57 % inkl. MwSt.',
            ]
        );

        $response->assertSessionDoesntHaveErrors();
        $preis = $listing->price->fresh();
        self::assertSame('provisionspflichtig', $preis->provision_typ->value);
        self::assertFalse($preis->provision_bestaetigt);
    }

    /**
     * Prüfbericht 2026-09-12, Befund 13: die Provisionsbestätigung darf
     * einen anderen Provisionstext oder -typ als den bestätigten nicht
     * überdauern, auch wenn "provision_bestaetigt" im selben Speichervorgang
     * unverändert mitgesendet wird (ein zuvor angehaktes Kästchen bestätigt
     * nicht automatisch einen neuen Text).
     */
    public function test_die_provisionsbestaetigung_wird_bei_geaenderten_provisionsangaben_zurueckgesetzt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->vollstaendig()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $this->actingAs($user)->patchJson(
            route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 4]),
            ['provision_typ' => 'provisionspflichtig', 'provision_text' => '3,57 % inkl. MwSt.', 'provision_bestaetigt' => '1']
        )->assertOk();
        self::assertTrue($listing->fresh()->price->provision_bestaetigt);

        // Nur der Provisionstext ändert sich, "provision_bestaetigt" wird in
        // diesem Autosave gar nicht mitgesendet.
        $this->actingAs($user)->patchJson(
            route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 4]),
            ['provision_typ' => 'provisionspflichtig', 'provision_text' => '7,14 % inkl. MwSt.']
        )->assertOk();
        self::assertFalse($listing->fresh()->price->provision_bestaetigt, 'Die Bestätigung darf einen anderen Provisionstext nicht überdauern.');
    }

    /**
     * Prüfbericht 2026-09-12, Befund 13: das versteckte "0"-Feld muss ein
     * deaktiviertes Kästchen im normalen Formular-POST übertragen, sonst
     * lässt sich eine erteilte Bestätigung nie zurücknehmen.
     */
    public function test_das_deaktivierte_kaestchen_nimmt_die_bestaetigung_im_formular_zurueck(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->vollstaendig()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);
        $listing->price()->update(['provision_typ' => ProvisionTyp::Provisionspflichtig, 'provision_text' => '3,57 % inkl. MwSt.', 'provision_bestaetigt' => true]);

        $seite = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 4]));
        $seite->assertOk();
        // Wie in schritt-6.blade.php üblich: ein verstecktes "0" vor der Checkbox.
        self::assertMatchesRegularExpression(
            '#<input type="hidden" name="provision_bestaetigt" value="0">\s*<input type="checkbox" name="provision_bestaetigt"#',
            $seite->getContent()
        );

        $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 4]),
            [
                'aktion' => 'speichern',
                'kaltmiete' => '800,00',
                'nebenkosten' => '200,00',
                'heizkosten_struktur' => 'zusaetzlich',
                'stellplatz_modus' => 'keiner',
                'provision_typ' => 'provisionspflichtig',
                'provision_text' => '3,57 % inkl. MwSt.',
                'provision_bestaetigt' => '0',
            ]
        )->assertSessionDoesntHaveErrors();

        self::assertFalse($listing->fresh()->price->provision_bestaetigt);
    }

    public function test_ein_kaufobjekt_speichert_den_kaufpreis_ohne_warmmiete(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->kauf()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 4]),
            [
                'aktion' => 'weiter',
                'kaufpreis' => '325.000,00',
                'provision_typ' => 'provisionsfrei',
                'stellplatz_modus' => 'keiner',
            ]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 5]));

        $preis = $listing->price()->first();
        self::assertSame(32_500_000, $preis->kaufpreis_cent);
        self::assertNull($preis->warmmiete_cent);
    }

    public function test_kauf_stellplatz_zusaetzlich_verlangt_einen_stellplatzkaufpreis_hinweis_nicht_fehler(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->kauf()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 4]),
            [
                'aktion' => 'weiter',
                'kaufpreis' => '325.000,00',
                'stellplatz_modus' => 'pflicht_zusaetzlich',
                'stellplatz_kaufpreis' => '15.000,00',
                'provision_typ' => 'provisionsfrei',
            ]
        );

        $response->assertSessionDoesntHaveErrors();
        $preis = $listing->price->fresh();
        self::assertSame(1_500_000, $preis->stellplatz_kaufpreis_cent);
        self::assertFalse((bool) $preis->stellplatz_im_kaufpreis);
    }
}
