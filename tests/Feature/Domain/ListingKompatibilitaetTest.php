<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\AdressFreigabe;
use App\Enums\HeizkostenStruktur;
use App\Enums\HeizkostenVersorgung;
use App\Enums\MediaTyp;
use App\Enums\MerkmalWert;
use App\Enums\Nutzungsstatus;
use App\Enums\StellplatzModus;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\ListingPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Abwärtskompatibilität des Datenmodells (Masterprompt-Abgleich B.2): der
 * bisherige Assistent schreibt weiter boolesche Merkmale, das Anzeigeflag
 * der Adresse und die Heizkostenflags; die neuen Felder folgen nach.
 */
final class ListingKompatibilitaetTest extends TestCase
{
    use RefreshDatabase;

    public function test_merkmal_liest_boolesche_werte_zeichenketten_und_fehlende_schluessel(): void
    {
        $listing = Listing::factory()->make([
            'ausstattung' => [
                'balkon' => true,
                'keller' => false,
                'aufzug' => 'ja',
                'garten' => 'nein',
                'terrasse' => 'unbekannt',
                'barrierefrei' => true,
            ],
        ]);

        self::assertSame(MerkmalWert::Ja, $listing->merkmal('balkon'));
        self::assertSame(MerkmalWert::Nein, $listing->merkmal('keller'));
        self::assertSame(MerkmalWert::Ja, $listing->merkmal('aufzug'));
        self::assertSame(MerkmalWert::Nein, $listing->merkmal('garten'));
        self::assertSame(MerkmalWert::Unbekannt, $listing->merkmal('terrasse'));
        self::assertSame(MerkmalWert::Unbekannt, $listing->merkmal('einbaukueche'));
        self::assertSame(MerkmalWert::Ja, $listing->merkmal('barrierearm'), 'Der ältere Schlüssel barrierefrei wird für barrierearm gelesen.');

        $merkmale = $listing->merkmale();
        self::assertArrayHasKey('wg_geeignet', $merkmale);
        self::assertArrayNotHasKey('barrierefrei', $merkmale);
        self::assertSame(MerkmalWert::Unbekannt, $merkmale['wg_geeignet']);

        // Ältere Daten bleiben unverändert lesbar.
        self::assertTrue($listing->ausstattung['balkon']);
        self::assertNull(Listing::factory()->make(['ausstattung' => null])->ausstattung);
        self::assertSame(MerkmalWert::Unbekannt, Listing::factory()->make(['ausstattung' => null])->merkmal('balkon'));
    }

    public function test_adressfreigabe_und_anzeigeflag_bleiben_in_beide_richtungen_konsistent(): void
    {
        $ueberFlag = Listing::factory()->create(['adresse_im_inserat_anzeigen' => false]);
        self::assertSame(AdressFreigabe::NurPlzOrt, $ueberFlag->fresh()->adress_freigabe);
        self::assertFalse($ueberFlag->fresh()->adresse_im_inserat_anzeigen);

        $ueberFreigabe = Listing::factory()->create(['adress_freigabe' => AdressFreigabe::NurPlzOrt]);
        self::assertFalse($ueberFreigabe->fresh()->adresse_im_inserat_anzeigen);

        $standard = Listing::factory()->create();
        self::assertSame(AdressFreigabe::Vollstaendig, $standard->fresh()->adress_freigabe);
        self::assertTrue($standard->fresh()->adresse_im_inserat_anzeigen);

        // Bisheriger Assistent: Flag ändern.
        $standard->update(['adresse_im_inserat_anzeigen' => false]);
        self::assertSame(AdressFreigabe::NurPlzOrt, $standard->fresh()->adress_freigabe);

        // Neuer Assistent: Freigabe ändern.
        $standard->update(['adress_freigabe' => AdressFreigabe::Vollstaendig]);
        self::assertTrue($standard->fresh()->adresse_im_inserat_anzeigen);
    }

    public function test_die_freigabe_eines_mediums_folgt_dem_typ_wenn_sie_nicht_gesetzt_ist(): void
    {
        $listing = Listing::factory()->create();

        $bild = ListingMedia::factory()->bild()->create(['listing_id' => $listing->id]);
        $grundriss = ListingMedia::factory()->grundriss()->create(['listing_id' => $listing->id]);
        $dokument = ListingMedia::factory()->dokument()->create(['listing_id' => $listing->id]);
        $ausweis = ListingMedia::factory()->energieausweis()->create(['listing_id' => $listing->id]);
        $ausdruecklich = ListingMedia::factory()->dokument()->create(['listing_id' => $listing->id, 'freigegeben' => true]);

        self::assertTrue($bild->fresh()->freigegeben);
        self::assertTrue($grundriss->fresh()->freigegeben);
        self::assertFalse($dokument->fresh()->freigegeben);
        self::assertFalse($ausweis->fresh()->freigegeben);
        self::assertTrue($ausdruecklich->fresh()->freigegeben);
        self::assertSame(0, $bild->fresh()->rotation);
        self::assertSame(MediaTyp::Energieausweis, $ausweis->fresh()->typ);
        self::assertSame(3, $listing->media()->freigegeben()->count());

        // Der bisherige Upload-Pfad ohne das Feld freigegeben.
        $alt = $listing->media()->create([
            'typ' => MediaTyp::Dokument,
            'dateiname_original' => 'expose.pdf',
            'pfad' => 'x/expose.pdf',
            'mime' => 'application/pdf',
            'groesse_bytes' => 2000,
            'sortierung' => 9,
            'im_inserat' => true,
            'pruefsumme_sha256' => hash('sha256', 'b'),
        ]);
        self::assertFalse($alt->fresh()->freigegeben);
        self::assertFalse($alt->fresh()->istVeroeffentlichbar());
    }

    public function test_heizkostenstruktur_leitet_flag_und_versorgung_ab(): void
    {
        $listing = Listing::factory()->miete()->mitPreisen()->create(['heizkosten_versorgung' => HeizkostenVersorgung::Zentral]);

        $listing->price->update(['heizkosten_struktur' => HeizkostenStruktur::Enthalten]);
        self::assertTrue($listing->price->fresh()->heizkosten_in_nebenkosten_enthalten);
        self::assertSame(HeizkostenVersorgung::Zentral, $listing->fresh()->heizkosten_versorgung);

        $listing->price->update(['heizkosten_struktur' => HeizkostenStruktur::EigenerVertrag]);
        self::assertFalse($listing->price->fresh()->heizkosten_in_nebenkosten_enthalten);
        self::assertSame(HeizkostenVersorgung::Dezentral, $listing->fresh()->heizkosten_versorgung);

        // Bisheriger Assistent ändert nur die Versorgung: die Struktur folgt.
        $listing = $listing->fresh(['price']);
        $listing->update(['heizkosten_versorgung' => HeizkostenVersorgung::Zentral]);
        self::assertSame(HeizkostenStruktur::Zusaetzlich, $listing->price->fresh()->heizkosten_struktur);

        // Bisheriger Assistent ändert nur das Flag: die Struktur folgt.
        $listing->price->fresh()->update(['heizkosten_in_nebenkosten_enthalten' => true]);
        self::assertSame(HeizkostenStruktur::Enthalten, $listing->price->fresh()->heizkosten_struktur);

        // Ohne gesetzte Struktur bleibt der ältere Stand unangetastet.
        $preis = ListingPrice::factory()->create(['heizkosten_struktur' => null, 'listing_id' => Listing::factory()->create()->id]);
        $preis->update(['heizkosten_in_nebenkosten_enthalten' => true]);
        self::assertNull($preis->fresh()->heizkosten_struktur);
    }

    public function test_neue_spalten_haben_standardwerte_fuer_den_bisherigen_assistenten(): void
    {
        $listing = Listing::factory()->create();
        $listing->price()->create(['kaltmiete_cent' => 80_000, 'nebenkosten_cent' => 20_000]);

        $roh = $listing->fresh(['price']);
        self::assertSame(StellplatzModus::Keiner, $roh->price->stellplatz_modus);
        self::assertFalse($roh->price->provision_bestaetigt);
        self::assertNull($roh->price->stellplatz_im_kaufpreis);
        self::assertNull($roh->gewerbe_unterart);
        self::assertNull($roh->interne_bezeichnung);
        self::assertInstanceOf(Nutzungsstatus::class, $roh->nutzungsstatus);
        self::assertSame(Nutzungsstatus::Unbekannt, Listing::factory()->create(['nutzungsstatus' => null])->fresh()->nutzungsstatus);
        self::assertSame(AdressFreigabe::Vollstaendig, Listing::factory()->create(['adress_freigabe' => null])->fresh()->adress_freigabe);
    }

    public function test_die_neuen_factory_zustaende_liefern_passende_objekte(): void
    {
        $gewerbe = Listing::factory()->gewerbe()->create();
        self::assertNull($gewerbe->wohnflaeche_qm);
        self::assertNotNull($gewerbe->gewerbeflaeche_qm);
        self::assertNotNull($gewerbe->gewerbe_unterart);

        $mfh = Listing::factory()->mehrfamilienhaus()->create();
        self::assertNotNull($mfh->grundstuecksflaeche_qm);
        self::assertNull($mfh->etage);

        $grundstueck = Listing::factory()->grundstueck()->create();
        self::assertNull($grundstueck->baujahr);
        self::assertNull($grundstueck->energietraeger);

        $stellplatz = Listing::factory()->stellplatz()->create();
        self::assertSame(1, $stellplatz->stellplatz_anzahl);

        $vermietet = Listing::factory()->vermietetZumVerkauf()->create();
        self::assertTrue($vermietet->istKauf());
        self::assertSame(Nutzungsstatus::Vermietet, $vermietet->nutzungsstatus);
        self::assertSame(9_600_00, $vermietet->price->mieteinnahmen_ist_cent);
    }
}
