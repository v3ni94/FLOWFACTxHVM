<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Enums\AdressFreigabe;
use App\Enums\TextFeld;
use App\Models\Listing;
use App\Services\Ai\FakeTextGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vorlagenmodus (Masterprompt Abschnitt 16, Masterprompt-Abgleich B.7):
 * regelbasierte Platzhaltertexte, die ausschließlich bestätigte Daten
 * nennen und ehrlich als Vorlage gekennzeichnet sind.
 */
final class FakeTextGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_modell_heisst_vorlage(): void
    {
        self::assertSame('vorlage', (new FakeTextGenerator)->modell());
    }

    public function test_die_lagebeschreibung_erfindet_keine_anbindung(): void
    {
        $listing = Listing::factory()->create(['ort' => 'Erkelenz', 'beschreibung_lage' => null]);

        $ergebnis = (new FakeTextGenerator)->generate($listing, [TextFeld::BeschreibungLage]);

        self::assertStringContainsString('Erkelenz', $ergebnis['beschreibung_lage']);
        self::assertStringNotContainsString('gute Anbindung', $ergebnis['beschreibung_lage']);
        self::assertStringNotContainsString('Anbindung', $ergebnis['beschreibung_lage']);
    }

    public function test_eine_manuell_vorhandene_lagebeschreibung_wird_uebernommen(): void
    {
        $listing = Listing::factory()->create([
            'ort' => 'Erkelenz',
            'beschreibung_lage' => 'Das Objekt liegt in einem ruhigen Wohngebiet.',
        ]);

        $ergebnis = (new FakeTextGenerator)->generate($listing, [TextFeld::BeschreibungLage]);

        self::assertSame('Das Objekt liegt in einem ruhigen Wohngebiet.', $ergebnis['beschreibung_lage']);
    }

    public function test_ohne_ort_und_ohne_manuellen_text_wird_dies_ehrlich_benannt(): void
    {
        $listing = Listing::factory()->create(['ort' => '', 'beschreibung_lage' => null]);

        $ergebnis = (new FakeTextGenerator)->generate($listing, [TextFeld::BeschreibungLage]);

        self::assertStringContainsString('keine bestätigten Angaben', $ergebnis['beschreibung_lage']);
    }

    /**
     * Masterprompt-Abgleich B.7: auch der Vorlagenmodus wird gegen eine
     * ausgeblendete Adresse geprüft, obwohl er selbst nie Straße oder
     * Hausnummer verwendet.
     */
    public function test_bleibt_bei_ausgeblendeter_adresse_ohne_ausnahme(): void
    {
        $listing = Listing::factory()->create([
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
            'strasse' => 'Geheimstraße',
            'hausnummer' => '12',
            'ort' => 'Erkelenz',
        ]);

        $ergebnis = (new FakeTextGenerator)->generate($listing, [TextFeld::Titel, TextFeld::BeschreibungLage]);

        self::assertArrayHasKey('titel', $ergebnis);
        self::assertStringNotContainsString('Geheimstraße', $ergebnis['titel']);
        self::assertStringNotContainsString('Geheimstraße', $ergebnis['beschreibung_lage']);
    }
}
