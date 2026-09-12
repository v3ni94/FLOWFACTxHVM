<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Enums\AdressFreigabe;
use App\Enums\TextFeld;
use App\Models\Listing;
use App\Models\ListingInternal;
use App\Models\User;
use App\Services\Ai\PromptBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PromptBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const string MARKER = 'INTERN-MARKER-XYZ';

    public function test_interne_markerwerte_erscheinen_nirgends_im_prompt(): void
    {
        $listing = Listing::factory()->create();
        ListingInternal::factory()->create([
            'listing_id' => $listing->id,
            'eigentuemer_name' => self::MARKER.' Eigentümer',
            'eigentuemer_kontakt' => self::MARKER.' Kontakt',
            'verwaltungsobjekt_referenz' => self::MARKER.' Referenz',
            'interne_notizen' => self::MARKER.' Notiz',
            'schluessel_hinweis' => self::MARKER.' Schlüssel',
            'besichtigung_intern' => self::MARKER.' Besichtigung',
            'kalkulation_notiz' => self::MARKER.' Kalkulation',
        ]);
        $listing->load('internal');

        $builder = new PromptBuilder;
        $system = $builder->systemPrompt();
        $nachricht = $builder->userMessage($listing, TextFeld::cases());

        self::assertStringNotContainsString(self::MARKER, $system);
        self::assertStringNotContainsString(self::MARKER, $nachricht);
    }

    public function test_die_uuid_und_der_ansprechpartner_erscheinen_nicht_im_prompt(): void
    {
        $ansprechpartner = User::factory()->create(['name' => 'MARKER-PERSON Schmitz']);
        $listing = Listing::factory()->create(['ansprechpartner_user_id' => $ansprechpartner->id]);

        $nachricht = (new PromptBuilder)->userMessage($listing, [TextFeld::Titel]);

        self::assertStringNotContainsString($listing->uuid, $nachricht);
        self::assertStringNotContainsString('MARKER-PERSON', $nachricht);
        self::assertStringNotContainsString($listing->objektnummer, $nachricht);
    }

    public function test_nur_die_angeforderten_feldschluessel_werden_im_json_auftrag_genannt(): void
    {
        $listing = Listing::factory()->create();

        $nachricht = (new PromptBuilder)->userMessage($listing, [TextFeld::Titel]);

        self::assertStringContainsString('titel', $nachricht);
        self::assertStringNotContainsString('beschreibung_objekt', $nachricht);
        self::assertStringNotContainsString('beschreibung_lage', $nachricht);
    }

    public function test_die_adresse_wird_bei_deaktivierter_anzeige_auf_plz_und_ort_reduziert(): void
    {
        $listing = Listing::factory()->create([
            'adresse_im_inserat_anzeigen' => false,
            'strasse' => 'Geheimstraße',
            'hausnummer' => '77Z',
            'plz' => '41812',
            'ort' => 'Erkelenz',
        ]);

        $nachricht = (new PromptBuilder)->userMessage($listing, [TextFeld::BeschreibungLage]);

        self::assertStringNotContainsString('Geheimstraße', $nachricht);
        self::assertStringNotContainsString('77Z', $nachricht);
        self::assertStringNotContainsString('Adresse:', $nachricht);
        self::assertStringContainsString('41812', $nachricht);
        self::assertStringContainsString('Erkelenz', $nachricht);
    }

    public function test_die_volladresse_erscheint_bei_aktivierter_anzeige(): void
    {
        $listing = Listing::factory()->create([
            'adresse_im_inserat_anzeigen' => true,
            'strasse' => 'Kölner Straße',
            'hausnummer' => '12',
        ]);

        $nachricht = (new PromptBuilder)->userMessage($listing, [TextFeld::BeschreibungLage]);

        self::assertStringContainsString('Kölner Straße', $nachricht);
        self::assertStringContainsString('12', $nachricht);
    }

    public function test_wahre_ausstattungsmerkmale_erscheinen_als_liste(): void
    {
        $listing = Listing::factory()->create([
            'ausstattung' => [
                'balkon' => true,
                'terrasse' => false,
                'garten' => false,
                'keller' => true,
                'aufzug' => false,
                'einbaukueche' => false,
                'gaeste_wc' => false,
                'barrierefrei' => false,
                'moebliert' => false,
                'wg_geeignet' => false,
                'haustiere_erlaubt' => false,
            ],
        ]);

        $nachricht = (new PromptBuilder)->userMessage($listing, [TextFeld::BeschreibungAusstattung]);

        self::assertStringContainsString('Balkon', $nachricht);
        self::assertStringContainsString('Keller', $nachricht);
        self::assertStringNotContainsString('Terrasse', $nachricht);
    }

    /**
     * Masterprompt-Abgleich B.2, B.8: "unbekannt" (Wert fehlt oder ist
     * ausdrücklich "unbekannt") wird ebenso wie "nein" ausgelassen, nur "ja"
     * erscheint im Prompt.
     */
    public function test_dreiwertige_merkmale_nur_ja_wird_genannt(): void
    {
        $listing = Listing::factory()->create([
            'ausstattung' => [
                'balkon' => 'ja',
                'keller' => 'nein',
                'aufzug' => 'unbekannt',
                // 'gaeste_wc' bewusst nicht gesetzt: fehlender Schlüssel gilt
                // ebenfalls als unbekannt.
            ],
        ]);

        $nachricht = (new PromptBuilder)->userMessage($listing, [TextFeld::BeschreibungAusstattung]);

        self::assertStringContainsString('Balkon', $nachricht);
        self::assertStringNotContainsString('Keller', $nachricht);
        self::assertStringNotContainsString('Aufzug', $nachricht);
        self::assertStringNotContainsString('Gäste-WC', $nachricht);
    }

    /**
     * Masterprompt-Abgleich B.7: bei Adressfreigabe "nur PLZ und Ort" erhält
     * der Prompt weder Straße noch Hausnummer und eine ausdrückliche Regel.
     */
    public function test_bei_ausgeblendeter_adresse_erhaelt_der_prompt_keine_strasse(): void
    {
        $listing = Listing::factory()->create([
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
            'strasse' => 'Geheimstraße',
            'hausnummer' => '77Z',
            'plz' => '41812',
            'ort' => 'Erkelenz',
        ]);

        $nachricht = (new PromptBuilder)->userMessage($listing, [TextFeld::Titel]);

        self::assertStringNotContainsString('Geheimstraße', $nachricht);
        self::assertStringNotContainsString('77Z', $nachricht);
        self::assertStringContainsString('Nennen Sie keine Straße und keine Hausnummer.', $nachricht);
    }

    public function test_ein_vermietetes_kaufobjekt_erhaelt_die_regel_nicht_bezugsfrei(): void
    {
        $listing = Listing::factory()->vermietetZumVerkauf()->create();

        $nachricht = (new PromptBuilder)->userMessage($listing, [TextFeld::Titel]);

        self::assertStringContainsString('Das Objekt ist vermietet und darf nicht als bezugsfrei beschrieben werden.', $nachricht);
    }

    public function test_ein_leerstehendes_mietobjekt_erhaelt_die_vermietet_regel_nicht(): void
    {
        $listing = Listing::factory()->create();

        $nachricht = (new PromptBuilder)->userMessage($listing, [TextFeld::Titel]);

        self::assertStringNotContainsString('darf nicht als bezugsfrei beschrieben werden', $nachricht);
    }

    /**
     * Masterprompt-Abgleich B.7: die Lagebeschreibung darf nur den manuell
     * gespeicherten Lagetext und die Adresse (PLZ/Ort) verwenden.
     */
    public function test_eine_vorhandene_lagebeschreibung_wird_als_bestaetigte_angabe_uebergeben(): void
    {
        $listing = Listing::factory()->create([
            'beschreibung_lage' => 'Das Objekt liegt in einem ruhigen Wohngebiet.',
        ]);

        $builder = new PromptBuilder;
        $nachricht = $builder->userMessage($listing, [TextFeld::BeschreibungLage]);

        self::assertStringContainsString('Vorhandene Lagebeschreibung: Das Objekt liegt in einem ruhigen Wohngebiet.', $nachricht);
        self::assertStringContainsString('Nennen Sie keine Entfernungen, Fahrzeiten', $builder->systemPrompt());
    }

    public function test_ohne_vorhandene_lagebeschreibung_wird_dies_ausdruecklich_vermerkt(): void
    {
        $listing = Listing::factory()->create(['beschreibung_lage' => null]);

        $nachricht = (new PromptBuilder)->userMessage($listing, [TextFeld::BeschreibungLage]);

        self::assertStringContainsString('Vorhandene Lagebeschreibung: keine.', $nachricht);
    }
}
