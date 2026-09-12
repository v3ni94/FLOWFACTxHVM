<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\AdressFreigabe;
use App\Enums\ListingStatus;
use App\Enums\TextFeld;
use App\Enums\TextQuelle;
use App\Models\Listing;
use App\Models\ListingChange;
use App\Models\ListingText;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Schritt 8: Beschreibungen (Masterprompt-Abgleich B.1 Schritt 8,
 * Masterprompt Abschnitt 16).
 */
final class Step8Test extends TestCase
{
    use RefreshDatabase;

    public function test_manuelle_aenderungen_werden_gespeichert_und_als_manueller_text_aufgezeichnet(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['beschreibung_objekt' => null]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 8]),
            [
                'beschreibung_objekt' => 'Diese Wohnung liegt zentral.',
                'beschreibung_ausstattung' => null,
                'beschreibung_lage' => null,
                'beschreibung_sonstiges' => null,
                'aktion' => 'weiter',
            ]
        );

        $response->assertRedirect(route('app.listings.review', $listing));

        $listing->refresh();
        $this->assertSame('Diese Wohnung liegt zentral.', $listing->beschreibung_objekt);
        $this->assertDatabaseHas('listing_texts', [
            'listing_id' => $listing->id,
            'feld' => TextFeld::BeschreibungObjekt->value,
            'quelle' => TextQuelle::Manuell->value,
            'uebernommen' => true,
        ]);
    }

    public function test_ein_manuell_bearbeiteter_text_bleibt_bei_erneuter_erzeugung_erhalten(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['beschreibung_objekt' => 'Handgeschriebener Text.']);

        $this->actingAs($user)->post(route('app.listings.texts.generate', $listing), [
            'felder' => [TextFeld::BeschreibungObjekt->value],
        ]);

        $listing->refresh();
        $this->assertSame('Handgeschriebener Text.', $listing->beschreibung_objekt);
    }

    public function test_das_pruefbeduerftig_abzeichen_erscheint_erst_nach_einer_datenaenderung(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['beschreibung_objekt' => null, 'wohnflaeche_qm' => 65]);

        // Text übernehmen: setzt den Datenbasis-Hash auf den aktuellen Stand.
        $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 8]),
            ['beschreibung_objekt' => 'Ausgangstext für die Prüfung.']
        );

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 8]));
        $response->assertOk();
        $response->assertDontSee('Prüfbedürftig');

        // Eine Datenänderung (z. B. die Wohnfläche) lässt den Hash abweichen.
        $listing->update(['wohnflaeche_qm' => 80]);

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 8]));
        $response->assertOk();
        $response->assertSee('Prüfbedürftig');
    }

    public function test_die_adressfreigabe_blockiert_einen_text_mit_strasse_und_hausnummer(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create([
            'strasse' => 'Kölner Straße',
            'hausnummer' => '12',
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
            'beschreibung_lage' => 'Die Wohnung liegt in der Kölner Straße 12 in ruhiger Lage.',
        ]);

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 8]));

        $response->assertOk();
        $response->assertSee('Dieser Text enthält Straße oder Hausnummer', false);
    }

    public function test_die_adressfreigabe_blockiert_die_veroeffentlichung_wenn_ein_text_die_strasse_enthaelt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create([
            'strasse' => 'Kölner Straße',
            'hausnummer' => '12',
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
            'status' => ListingStatus::Bereit,
        ]);
        $listing->update(['beschreibung_lage' => 'Nur wenige Meter von der Kölner Straße 12 entfernt.']);

        $response = $this->actingAs($user)->post(route('app.listings.publish', $listing), [
            'portale' => ['immoscout24'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Straße oder Hausnummer', session('error'));
        $this->assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    public function test_ohne_adressleck_bleibt_die_hausnummer_unerkannt_wenn_sie_nicht_vorkommt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create([
            'strasse' => 'Kölner Straße',
            'hausnummer' => '12',
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
            'beschreibung_lage' => 'Die Wohnung liegt sehr ruhig und zentral.',
        ]);

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 8]));

        $response->assertOk();
        $response->assertDontSee('Dieser Text enthält Straße oder Hausnummer', false);
    }

    public function test_die_autosave_akzeptiert_ein_einzelnes_feld(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['beschreibung_sonstiges' => null]);

        $response = $this->actingAs($user)->patchJson(
            route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 8]),
            ['beschreibung_sonstiges' => 'Alle Angaben ohne Gewähr.']
        );

        $response->assertOk();
        $this->assertSame('Alle Angaben ohne Gewähr.', $listing->fresh()->beschreibung_sonstiges);
    }

    public function test_die_datenbasis_wird_beim_manuellen_speichern_gesetzt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['beschreibung_objekt' => null]);

        $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 8]),
            ['beschreibung_objekt' => 'Ein geprüfter Text.']
        );

        $listing->refresh();
        $text = $listing->texts()->where('feld', TextFeld::BeschreibungObjekt->value)->latest('id')->first();

        $this->assertNotNull($text);
        $this->assertSame((new ListingContentHasher)->hash($listing), $text->datenbasis_hash);
    }

    /**
     * Prüfbericht 2026-09-12, Befund 6: der Autosave in Schritt 8 darf keine
     * listing_texts-Zeile anlegen (nur store() tut das), und aufeinanderfolgende
     * Änderungen derselben Textspalte durch denselben Benutzer innerhalb von
     * zehn Minuten werden vom Observer zu einer listing_changes-Zeile
     * zusammengefasst statt bei jedem Autosave eine neue anzulegen.
     */
    public function test_autosave_in_schritt_8_erzeugt_keine_listing_texts_zeilen_und_buendelt_die_historie(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);
        $text = '';

        for ($i = 1; $i <= 5; $i++) {
            $text .= 'Satz '.$i.' der Objektbeschreibung mit einigen Worten. ';
            $this->actingAs($user)->patchJson(
                route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 8]),
                ['beschreibung_objekt' => $text]
            )->assertOk();
        }

        self::assertSame(0, ListingText::query()->where('listing_id', $listing->id)->count());

        $aenderungen = ListingChange::query()->where('listing_id', $listing->id)->where('feld', 'listings.beschreibung_objekt')->get();
        self::assertCount(1, $aenderungen, 'Fünf Autosaves innerhalb weniger Sekunden dürfen nur eine gebündelte Historienzeile erzeugen.');
        self::assertSame('', (string) $aenderungen->first()->alt);
        // TrimStrings (Middleware) entfernt das abschließende Leerzeichen aus der Eingabe.
        self::assertSame(rtrim($text), $aenderungen->first()->neu);

        // Erst "Weiter" schreibt eine listing_texts-Zeile (mit einer
        // tatsächlichen inhaltlichen Änderung, sonst überspringt store()
        // unveränderte Felder ebenso wie der Autosave).
        $text .= 'Abschlusssatz nach dem Speichern.';
        $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 8]),
            ['beschreibung_objekt' => $text, 'aktion' => 'weiter']
        )->assertRedirect();

        self::assertSame(1, ListingText::query()->where('listing_id', $listing->id)->count());
    }
}
