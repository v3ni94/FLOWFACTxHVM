<?php

declare(strict_types=1);

namespace Tests\Unit\Flowfact;

use App\Domain\Listing\Merkmale;
use App\Domain\Listing\PublishableFields;
use App\Flowfact\Mapping\FieldCatalog;
use PHPUnit\Framework\TestCase;

final class FieldCatalogTest extends TestCase
{
    public function test_jedes_positivlistenfeld_hat_einen_katalogeintrag(): void
    {
        foreach (PublishableFields::LISTING as $feld) {
            self::assertArrayHasKey($feld, FieldCatalog::FELDER, "Objektfeld {$feld} fehlt im FieldCatalog.");
        }

        foreach (PublishableFields::PRICE as $feld) {
            self::assertArrayHasKey($feld, FieldCatalog::FELDER, "Preisfeld {$feld} fehlt im FieldCatalog.");
        }

        foreach (PublishableFields::ENERGY as $feld) {
            self::assertArrayHasKey('energie.'.$feld, FieldCatalog::FELDER, "Energiefeld {$feld} fehlt im FieldCatalog.");
        }
    }

    public function test_alle_merkmale_haben_einen_katalogeintrag(): void
    {
        foreach (Merkmale::schluessel() as $schluessel) {
            self::assertContains($schluessel, FieldCatalog::AUSSTATTUNG_SCHLUESSEL, "Merkmal {$schluessel} fehlt in AUSSTATTUNG_SCHLUESSEL.");
            self::assertArrayHasKey('ausstattung.'.$schluessel, FieldCatalog::FELDER, "Merkmal {$schluessel} fehlt im FieldCatalog.");
        }

        self::assertSame('barrierfree', FieldCatalog::FELDER['ausstattung.barrierearm']['ziel']);
    }

    public function test_der_katalog_kennt_keine_internen_felder(): void
    {
        $intern = ['eigentuemer_name', 'eigentuemer_kontakt', 'verwaltungsobjekt_referenz', 'interne_notizen', 'schluessel_hinweis', 'besichtigung_intern', 'kalkulation_notiz'];

        foreach ($intern as $feld) {
            self::assertArrayNotHasKey($feld, FieldCatalog::FELDER);
        }

        foreach (array_keys(FieldCatalog::FELDER) as $feld) {
            $basis = str_replace(['energie.', 'ausstattung.'], '', $feld);
            $erlaubt = in_array($feld, PublishableFields::LISTING, true)
                || in_array($feld, PublishableFields::PRICE, true)
                || (str_starts_with($feld, 'energie.') && in_array($basis, PublishableFields::ENERGY, true))
                || (str_starts_with($feld, 'ausstattung.') && in_array($basis, FieldCatalog::AUSSTATTUNG_SCHLUESSEL, true))
                || $feld === FieldCatalog::ADRESSFELD;

            self::assertTrue($erlaubt, "Katalogschlüssel {$feld} ist kein Positivlistenfeld.");
        }
    }

    public function test_bestaetigte_codes_aus_dem_connector_entwurf(): void
    {
        $codes = FieldCatalog::codeGruppen();

        self::assertSame('01ETAG', $codes['objektart']['wohnung']);
        self::assertSame('02EFH', $codes['objektart']['haus']);
        self::assertSame('02MFH', $codes['objektart']['mehrfamilienhaus']);
        self::assertSame('06B', $codes['objektart']['gewerbe']);
        self::assertSame('03BE', $codes['objektart']['grundstueck']);
        self::assertNull($codes['objektart']['stellplatz']);
        self::assertSame('06B', $codes['gewerbe_unterart']['buero']);
        self::assertSame('05L', $codes['gewerbe_unterart']['laden']);
        self::assertNull($codes['gewerbe_unterart']['lager']);
        self::assertContains('gewerbe_unterart.lager', FieldCatalog::UNBESTAETIGTE_CODES);
        self::assertSame('commercialarea', FieldCatalog::FELDER['gewerbeflaeche_qm']['ziel']);
        self::assertSame('let', FieldCatalog::FELDER['nutzungsstatus']['ziel']);
        self::assertSame(FieldCatalog::VERMIETET_FLAG, FieldCatalog::FELDER['nutzungsstatus']['art']);
        self::assertSame('07', $codes['zustand']['gepflegt']);
        self::assertSame('01', $codes['effizienzklasse']['A+']);
        self::assertSame('09', $codes['effizienzklasse']['H']);
        self::assertSame('7', $codes['stellplatz_typ']['tiefgarage']);
        self::assertSame('1', $codes['stellplatz_typ']['keiner']);
        self::assertContains('zustand.saniert', FieldCatalog::UNBESTAETIGTE_CODES);
    }
}
