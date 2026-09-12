<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Domain\Listing\InvalidRentInputException;
use App\Domain\Listing\PriceStructure;
use App\Domain\Listing\RentHinweis;
use App\Enums\HeizkostenStruktur;
use App\Enums\HeizkostenVersorgung;
use App\Enums\StellplatzModus;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Abbildung der Kostenstruktur auf den unveränderten RentCalculator und
 * Stellplatzmodi (Masterprompt-Abgleich B.1 Schritt 4, B.2, B.8).
 */
final class PriceStructureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{HeizkostenStruktur, HeizkostenVersorgung, bool}>
     */
    public static function strukturen(): array
    {
        return [
            'enthalten' => [HeizkostenStruktur::Enthalten, HeizkostenVersorgung::Zentral, true],
            'zusaetzlich' => [HeizkostenStruktur::Zusaetzlich, HeizkostenVersorgung::Zentral, false],
            'eigener_vertrag' => [HeizkostenStruktur::EigenerVertrag, HeizkostenVersorgung::Dezentral, false],
        ];
    }

    #[DataProvider('strukturen')]
    public function test_struktur_wird_auf_die_rechnerparameter_und_zurueck_abgebildet(HeizkostenStruktur $struktur, HeizkostenVersorgung $versorgung, bool $enthalten): void
    {
        self::assertSame($versorgung, PriceStructure::versorgung($struktur));
        self::assertSame($enthalten, PriceStructure::heizkostenEnthalten($struktur));
        self::assertSame($struktur, PriceStructure::struktur($versorgung, $enthalten));
    }

    public function test_ohne_versorgung_gibt_es_keine_struktur(): void
    {
        self::assertNull(PriceStructure::struktur(null, false));
        self::assertNull(PriceStructure::struktur(null, true));
    }

    public function test_berechnung_je_struktur_entspricht_dem_datenvertrag(): void
    {
        $struktur = new PriceStructure;

        // Fall A: zusätzlich, Heizkosten werden addiert.
        self::assertSame(110_000, $struktur->berechne(80_000, 20_000, 10_000, HeizkostenStruktur::Zusaetzlich)->warmmieteCent);

        // Fall B: enthalten, Heizkosten nicht erneut addiert.
        self::assertSame(110_000, $struktur->berechne(80_000, 30_000, 10_000, HeizkostenStruktur::Enthalten)->warmmieteCent);

        // Fall D: eigener Vertrag, Versorgerhinweis.
        $ergebnis = $struktur->berechne(80_000, 15_000, null, HeizkostenStruktur::EigenerVertrag);
        self::assertSame(95_000, $ergebnis->warmmieteCent);
        self::assertTrue($ergebnis->hatHinweis(RentHinweis::HeizkostenBeimVersorger));

        // Fall E: eigener Vertrag mit Heizkosten ist ungültig.
        $this->expectException(InvalidRentInputException::class);
        $struktur->berechne(80_000, 15_000, 5_000, HeizkostenStruktur::EigenerVertrag);
    }

    public function test_apply_leitet_flag_und_versorgung_ab_und_berechnet_die_warmmiete_neu(): void
    {
        $listing = Listing::factory()->miete()->mitPreisen()->create();
        $listing->load('price');

        $preis = (new PriceStructure)->apply($listing, HeizkostenStruktur::Enthalten);

        self::assertTrue($preis->heizkosten_in_nebenkosten_enthalten);
        self::assertSame(HeizkostenStruktur::Enthalten, $preis->fresh()->heizkosten_struktur);
        self::assertSame(HeizkostenVersorgung::Zentral, $listing->fresh()->heizkosten_versorgung);
        self::assertSame(100_000, $preis->fresh()->warmmiete_cent, 'Enthalten: Kaltmiete plus Nebenkosten ohne erneute Heizkosten.');

        (new PriceStructure)->apply($listing, HeizkostenStruktur::EigenerVertrag);

        $listing->refresh();
        self::assertSame(HeizkostenVersorgung::Dezentral, $listing->heizkosten_versorgung);
        self::assertNull($listing->price->heizkosten_cent, 'Eigener Vertrag: erfasste Heizkosten werden entfernt.');
        self::assertFalse($listing->price->heizkosten_in_nebenkosten_enthalten);
        self::assertSame(100_000, $listing->price->warmmiete_cent);
        self::assertSame(HeizkostenStruktur::EigenerVertrag, PriceStructure::ermittle($listing));
    }

    public function test_ermittle_faellt_auf_die_bisherigen_flags_zurueck(): void
    {
        $listing = Listing::factory()->miete()->create(['heizkosten_versorgung' => HeizkostenVersorgung::Dezentral]);
        $listing->price()->create(['kaltmiete_cent' => 80_000, 'nebenkosten_cent' => 15_000, 'heizkosten_struktur' => null]);

        self::assertSame(HeizkostenStruktur::EigenerVertrag, PriceStructure::ermittle($listing->fresh(['price'])));
    }

    public function test_optionaler_stellplatz_wird_getrennt_ausgewiesen_und_nie_addiert(): void
    {
        $darstellung = PriceStructure::gesamtdarstellung(110_000, StellplatzModus::Optional, 5_000);

        self::assertSame(110_000, $darstellung['warmmiete_cent']);
        self::assertSame(5_000, $darstellung['stellplatz_getrennt_cent']);
        self::assertSame(PriceStructure::HINWEIS_STELLPLATZ_OPTIONAL, $darstellung['hinweis']);
        self::assertTrue(PriceStructure::stellplatzGetrenntAusgewiesen(StellplatzModus::Optional));
    }

    public function test_verpflichtender_zusaetzlicher_stellplatz_wird_getrennt_ausgewiesen_und_nie_addiert(): void
    {
        $darstellung = PriceStructure::gesamtdarstellung(110_000, StellplatzModus::PflichtZusaetzlich, 7_500);

        self::assertSame(110_000, $darstellung['warmmiete_cent']);
        self::assertSame(7_500, $darstellung['stellplatz_getrennt_cent']);
        self::assertSame(PriceStructure::HINWEIS_STELLPLATZ_PFLICHT_ZUSAETZLICH, $darstellung['hinweis']);
    }

    public function test_verpflichtend_enthaltener_stellplatz_hat_keinen_eigenen_betrag(): void
    {
        $darstellung = PriceStructure::gesamtdarstellung(110_000, StellplatzModus::PflichtEnthalten, null);

        self::assertSame(110_000, $darstellung['warmmiete_cent']);
        self::assertNull($darstellung['stellplatz_getrennt_cent']);
        self::assertSame(PriceStructure::HINWEIS_STELLPLATZ_PFLICHT_ENTHALTEN, $darstellung['hinweis']);
        self::assertFalse(PriceStructure::stellplatzGetrenntAusgewiesen(StellplatzModus::PflichtEnthalten));
    }

    public function test_verpflichtend_enthaltener_stellplatz_mit_betrag_ist_ein_validierungsfehler(): void
    {
        $this->expectException(InvalidRentInputException::class);

        PriceStructure::pruefeStellplatz(StellplatzModus::PflichtEnthalten, 5_000);
    }

    public function test_verpflichtend_enthaltener_stellplatz_mit_kaufpreisbetrag_ist_ein_validierungsfehler(): void
    {
        $this->expectException(InvalidRentInputException::class);

        PriceStructure::pruefeStellplatz(StellplatzModus::PflichtEnthalten, null, 15_000_00);
    }

    public function test_kein_stellplatz_liefert_keinen_hinweis_und_keinen_betrag(): void
    {
        $darstellung = PriceStructure::gesamtdarstellung(95_000, StellplatzModus::Keiner, null);

        self::assertSame(95_000, $darstellung['warmmiete_cent']);
        self::assertNull($darstellung['stellplatz_getrennt_cent']);
        self::assertNull($darstellung['hinweis']);
    }
}
