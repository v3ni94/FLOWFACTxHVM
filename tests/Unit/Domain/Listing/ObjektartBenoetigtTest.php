<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Domain\Listing\Merkmale;
use App\Enums\Objektart;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Feldgruppen je Objektart (Masterprompt-Abgleich B.1 Schritt 3, B.2).
 */
final class ObjektartBenoetigtTest extends TestCase
{
    /**
     * @return array<string, array{Objektart, list<string>}>
     */
    public static function matrix(): array
    {
        return [
            'wohnung' => [Objektart::Wohnung, ['wohnflaeche', 'nutzflaeche', 'zimmer', 'etage', 'wohnungsausstattung', 'energieausweis']],
            'haus' => [Objektart::Haus, ['wohnflaeche', 'grundstueck', 'nutzflaeche', 'zimmer', 'wohnungsausstattung', 'energieausweis']],
            'mehrfamilienhaus' => [Objektart::Mehrfamilienhaus, ['wohnflaeche', 'grundstueck', 'nutzflaeche', 'wohnungsausstattung', 'energieausweis']],
            'gewerbe' => [Objektart::Gewerbe, ['nutzflaeche', 'gewerbeflaeche', 'etage', 'energieausweis']],
            'grundstueck' => [Objektart::Grundstueck, ['grundstueck']],
            'stellplatz' => [Objektart::Stellplatz, []],
        ];
    }

    /**
     * @param  list<string>  $erwartet
     */
    #[DataProvider('matrix')]
    public function test_benoetigt_folgt_der_matrix(Objektart $objektart, array $erwartet): void
    {
        foreach (Objektart::feldgruppen() as $gruppe) {
            self::assertSame(
                in_array($gruppe, $erwartet, true),
                $objektart->benoetigt($gruppe),
                sprintf('%s / %s', $objektart->value, $gruppe),
            );
        }
    }

    public function test_grundstueck_und_stellplatz_haben_weder_energieausweis_noch_zimmer(): void
    {
        foreach ([Objektart::Grundstueck, Objektart::Stellplatz] as $objektart) {
            self::assertFalse($objektart->benoetigt('energieausweis'));
            self::assertFalse($objektart->benoetigt('zimmer'));
            self::assertFalse($objektart->istWohnobjekt());
        }
    }

    public function test_gewerbe_verlangt_gewerbeflaeche_statt_wohnflaeche(): void
    {
        self::assertTrue(Objektart::Gewerbe->benoetigt('gewerbeflaeche'));
        self::assertFalse(Objektart::Gewerbe->benoetigt('wohnflaeche'));
        self::assertFalse(Objektart::Gewerbe->istWohnobjekt());
    }

    public function test_mehrfamilienhaus_hat_wohnflaeche_und_grundstueck_aber_keine_einzelne_etage(): void
    {
        self::assertTrue(Objektart::Mehrfamilienhaus->benoetigt('wohnflaeche'));
        self::assertTrue(Objektart::Mehrfamilienhaus->benoetigt('grundstueck'));
        self::assertFalse(Objektart::Mehrfamilienhaus->benoetigt('etage'));
        self::assertTrue(Objektart::Mehrfamilienhaus->istWohnobjekt());
    }

    public function test_unbekannte_feldgruppe_wird_nicht_benoetigt(): void
    {
        self::assertFalse(Objektart::Wohnung->benoetigt('gibt_es_nicht'));
    }

    public function test_options_enthaelt_mehrfamilienhaus(): void
    {
        self::assertSame('Mehrfamilienhaus', Objektart::options()['mehrfamilienhaus']);
    }

    public function test_merkmale_sind_je_objektart_zugeordnet(): void
    {
        self::assertCount(20, Merkmale::schluessel());
        self::assertSame([], Merkmale::fuerObjektart(Objektart::Grundstueck));
        self::assertSame([], Merkmale::fuerObjektart(Objektart::Stellplatz));
        self::assertContains('aufzug', Merkmale::fuerObjektart(Objektart::Gewerbe));
        self::assertNotContains('balkon', Merkmale::fuerObjektart(Objektart::Gewerbe));
        self::assertContains('wg_geeignet', Merkmale::fuerObjektart(Objektart::Wohnung));
        self::assertNotContains('wg_geeignet', Merkmale::fuerObjektart(Objektart::Haus));
        self::assertSame('Gäste-WC', Merkmale::label('gaeste_wc'));

        foreach (Merkmale::schluessel() as $schluessel) {
            self::assertArrayHasKey($schluessel, Merkmale::OBJEKTARTEN, $schluessel);
        }
    }
}
