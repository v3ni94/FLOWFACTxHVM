<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Listing;

use App\Enums\AdressFreigabe;
use App\Enums\EnergieausweisStatus;
use App\Enums\GewerbeUnterart;
use App\Enums\HeizkostenStruktur;
use App\Enums\MediaTyp;
use App\Enums\MerkmalWert;
use App\Enums\Nutzungsstatus;
use App\Enums\PortalStatus;
use App\Enums\PruefArt;
use App\Enums\PruefEbene;
use App\Enums\ReleaseAktion;
use App\Enums\StellplatzModus;
use App\Enums\Waermeabgabe;
use App\Enums\Warmwasserbereitung;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Neue und erweiterte Enums (Masterprompt-Abgleich B.2, B.4, B.6).
 */
final class MasterpromptEnumsTest extends TestCase
{
    /**
     * @return array<string, array{class-string<\BackedEnum>, list<string>}>
     */
    public static function neueEnums(): array
    {
        return [
            'GewerbeUnterart' => [GewerbeUnterart::class, ['buero', 'laden', 'lager', 'sonstiges']],
            'Nutzungsstatus' => [Nutzungsstatus::class, ['leerstehend', 'vermietet', 'anderweitig_belegt', 'unbekannt']],
            'StellplatzModus' => [StellplatzModus::class, ['keiner', 'optional', 'pflicht_enthalten', 'pflicht_zusaetzlich']],
            'HeizkostenStruktur' => [HeizkostenStruktur::class, ['enthalten', 'zusaetzlich', 'eigener_vertrag']],
            'Waermeabgabe' => [Waermeabgabe::class, ['heizkoerper', 'fussbodenheizung', 'beides', 'unbekannt']],
            'Warmwasserbereitung' => [Warmwasserbereitung::class, ['zentral', 'dezentral', 'unbekannt']],
            'MerkmalWert' => [MerkmalWert::class, ['ja', 'nein', 'unbekannt']],
            'AdressFreigabe' => [AdressFreigabe::class, ['vollstaendig', 'nur_plz_ort']],
            'PruefEbene' => [PruefEbene::class, ['intern', 'flowfact', 'portal', 'gesetzlich']],
            'PruefArt' => [PruefArt::class, ['blockierend', 'hinweis']],
            'ReleaseAktion' => [ReleaseAktion::class, ['flowfact_speichern', 'veroeffentlichen', 'deaktivieren']],
        ];
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     * @param  list<string>  $werte
     */
    #[DataProvider('neueEnums')]
    public function test_werte_labels_und_options(string $enum, array $werte): void
    {
        self::assertSame($werte, array_map(fn (\BackedEnum $fall): string => (string) $fall->value, $enum::cases()));

        $options = $enum::options();
        self::assertSame($werte, array_keys($options));

        foreach ($enum::cases() as $fall) {
            self::assertNotSame('', $fall->label());
            self::assertSame($fall->label(), $options[$fall->value]);
        }
    }

    public function test_energieausweisstatus_normalisiert_die_aelteren_werte(): void
    {
        self::assertSame(EnergieausweisStatus::Vorhanden, EnergieausweisStatus::LiegtVor->normalisiert());
        self::assertSame(EnergieausweisStatus::Beauftragt, EnergieausweisStatus::InErstellung->normalisiert());
        self::assertSame(EnergieausweisStatus::AusnahmeZuPruefen, EnergieausweisStatus::NichtErforderlich->normalisiert());

        foreach (EnergieausweisStatus::aktuelleWerte() as $status) {
            self::assertSame($status, $status->normalisiert());
        }

        // Ältere Werte bleiben lesbar (Bestandsdaten), erscheinen aber nicht mehr in der Auswahl.
        self::assertSame(EnergieausweisStatus::LiegtVor, EnergieausweisStatus::from('liegt_vor'));
        self::assertSame(['vorhanden', 'noch_nicht_vorhanden', 'beauftragt', 'ausnahme_zu_pruefen'], array_keys(EnergieausweisStatus::options()));
    }

    public function test_portalstatus_kennt_die_neuen_zustaende_mit_badges(): void
    {
        self::assertSame('badge-warning', PortalStatus::ManuelleFreigabeErforderlich->badgeClass());
        self::assertSame('badge-warning', PortalStatus::DeaktivierungAngefordert->badgeClass());
        self::assertSame('badge-neutral', PortalStatus::DeaktivierungBestaetigt->badgeClass());
        self::assertSame('manuelle_freigabe_erforderlich', PortalStatus::ManuelleFreigabeErforderlich->value);
        self::assertFalse(PortalStatus::ManuelleFreigabeErforderlich->istOffen());
        self::assertTrue(PortalStatus::Aktiv->istOffen());

        foreach (PortalStatus::cases() as $status) {
            self::assertNotSame('', $status->label());
        }
    }

    public function test_mediatyp_energieausweis_ist_standardmaessig_nicht_freigegeben(): void
    {
        self::assertTrue(MediaTyp::Bild->standardFreigegeben());
        self::assertTrue(MediaTyp::Grundriss->standardFreigegeben());
        self::assertFalse(MediaTyp::Dokument->standardFreigegeben());
        self::assertFalse(MediaTyp::Energieausweis->standardFreigegeben());
        self::assertSame('Energieausweis', MediaTyp::options()['energieausweis']);
    }

    public function test_merkmalwert_liest_boolesche_und_textwerte(): void
    {
        self::assertSame(MerkmalWert::Ja, MerkmalWert::aus(true));
        self::assertSame(MerkmalWert::Nein, MerkmalWert::aus(false));
        self::assertSame(MerkmalWert::Unbekannt, MerkmalWert::aus(null));
        self::assertSame(MerkmalWert::Ja, MerkmalWert::aus('ja'));
        self::assertSame(MerkmalWert::Nein, MerkmalWert::aus('nein'));
        self::assertSame(MerkmalWert::Unbekannt, MerkmalWert::aus('unbekannt'));
        self::assertSame(MerkmalWert::Ja, MerkmalWert::aus(1));
        self::assertSame(MerkmalWert::Nein, MerkmalWert::aus('0'));
        self::assertSame(MerkmalWert::Unbekannt, MerkmalWert::aus('vielleicht'));
        self::assertTrue(MerkmalWert::Ja->alsBool());
        self::assertFalse(MerkmalWert::Nein->alsBool());
        self::assertNull(MerkmalWert::Unbekannt->alsBool());
    }

    public function test_adressfreigabe_leitet_das_anzeigeflag_ab(): void
    {
        self::assertTrue(AdressFreigabe::Vollstaendig->adresseAnzeigen());
        self::assertFalse(AdressFreigabe::NurPlzOrt->adresseAnzeigen());
        self::assertSame(AdressFreigabe::NurPlzOrt, AdressFreigabe::ausAnzeigen(false));
        self::assertSame(AdressFreigabe::Vollstaendig, AdressFreigabe::ausAnzeigen(true));
    }

    public function test_stellplatzmodus_verlangt_nur_bei_optional_und_pflicht_zusaetzlich_einen_betrag(): void
    {
        self::assertFalse(StellplatzModus::Keiner->verlangtBetrag());
        self::assertTrue(StellplatzModus::Optional->verlangtBetrag());
        self::assertFalse(StellplatzModus::PflichtEnthalten->verlangtBetrag());
        self::assertTrue(StellplatzModus::PflichtZusaetzlich->verlangtBetrag());
    }
}
