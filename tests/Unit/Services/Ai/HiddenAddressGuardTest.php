<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Enums\AdressFreigabe;
use App\Enums\TextFeld;
use App\Models\Listing;
use App\Services\Ai\HiddenAddressGuard;
use App\Services\Ai\TextGenerationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Masterprompt-Abgleich B.7: letzte Sicherung gegen eine ausgeblendete
 * Adresse in erzeugten Texten, unabhängig vom PromptBuilder.
 */
final class HiddenAddressGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_erlaubt_texte_ohne_die_ausgeblendete_strasse(): void
    {
        $listing = Listing::factory()->create([
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
            'strasse' => 'Geheimstraße',
            'hausnummer' => '12',
        ]);

        HiddenAddressGuard::pruefe($listing, [TextFeld::Titel->value => 'Gepflegte Wohnung in Erkelenz.']);

        $this->addToAssertionCount(1);
    }

    public function test_wirft_bei_genannter_strasse(): void
    {
        $listing = Listing::factory()->create([
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
            'strasse' => 'Geheimstraße',
            'hausnummer' => '12',
        ]);

        $this->expectException(TextGenerationException::class);

        HiddenAddressGuard::pruefe($listing, [
            TextFeld::BeschreibungLage->value => 'Das Objekt liegt in der Geheimstraße.',
        ]);
    }

    public function test_wirft_bei_genannter_hausnummer_mit_wortgrenze(): void
    {
        $listing = Listing::factory()->create([
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
            'strasse' => null,
            'hausnummer' => '12',
        ]);

        $this->expectException(TextGenerationException::class);

        HiddenAddressGuard::pruefe($listing, [
            TextFeld::BeschreibungLage->value => 'Die Wohnung befindet sich im Haus Nummer 12.',
        ]);
    }

    public function test_eine_zufaellige_zahl_die_die_hausnummer_nur_als_teilstring_enthaelt_loest_nicht_aus(): void
    {
        $listing = Listing::factory()->create([
            'adress_freigabe' => AdressFreigabe::NurPlzOrt,
            'strasse' => null,
            'hausnummer' => '12',
        ]);

        HiddenAddressGuard::pruefe($listing, [
            TextFeld::BeschreibungObjekt->value => 'Das Baujahr liegt bei 1912 und es gibt 112 Quadratmeter.',
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_bei_vollstaendiger_adressfreigabe_greift_die_pruefung_nicht(): void
    {
        $listing = Listing::factory()->create([
            'adress_freigabe' => AdressFreigabe::Vollstaendig,
            'strasse' => 'Kölner Straße',
            'hausnummer' => '12',
        ]);

        HiddenAddressGuard::pruefe($listing, [
            TextFeld::BeschreibungLage->value => 'Das Objekt liegt in der Kölner Straße 12.',
        ]);

        $this->addToAssertionCount(1);
    }
}
