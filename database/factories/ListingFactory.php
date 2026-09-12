<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AdressFreigabe;
use App\Enums\Ausstattungsqualitaet;
use App\Enums\Ausweistyp;
use App\Enums\Effizienzklasse;
use App\Enums\EnergieausweisStatus;
use App\Enums\Energietraeger;
use App\Enums\GewerbeUnterart;
use App\Enums\HeizkostenStruktur;
use App\Enums\HeizkostenVersorgung;
use App\Enums\Heizungsart;
use App\Enums\ListingStatus;
use App\Enums\MediaTyp;
use App\Enums\Nutzungsstatus;
use App\Enums\Objektart;
use App\Enums\ProvisionTyp;
use App\Enums\StellplatzModus;
use App\Enums\StellplatzTyp;
use App\Enums\VerfuegbarAbTyp;
use App\Enums\Vermarktungsart;
use App\Enums\Zustand;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    protected $model = Listing::class;

    /**
     * Standardmäßig eine Mietwohnung in Erkelenz mit erfundenen Platzhalterdaten,
     * ohne reale Personen.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vermarktungsart' => Vermarktungsart::Miete,
            'objektart' => Objektart::Wohnung,
            'gewerbe_unterart' => null,
            'nutzungsstatus' => Nutzungsstatus::Leerstehend,
            'titel' => 'Gepflegte 3-Zimmer-Wohnung in Erkelenz',
            'interne_bezeichnung' => null,
            'strasse' => 'Kölner Straße',
            'hausnummer' => (string) fake()->numberBetween(1, 199),
            'adresszusatz' => null,
            'plz' => '41812',
            'ort' => 'Erkelenz',
            'stadtteil' => null,
            'land' => 'DE',
            'adresse_im_inserat_anzeigen' => true,
            'adress_freigabe' => fn (array $attributes): AdressFreigabe => AdressFreigabe::ausAnzeigen((bool) ($attributes['adresse_im_inserat_anzeigen'] ?? true)),
            'wohnflaeche_qm' => 65.00,
            'nutzflaeche_qm' => null,
            'gewerbeflaeche_qm' => null,
            'grundstuecksflaeche_qm' => null,
            'zimmer' => 3.0,
            'schlafzimmer' => 2,
            'badezimmer' => 1,
            'etage' => 2,
            'etagen_gesamt' => 4,
            'baujahr' => 1998,
            'modernisierungsjahr' => null,
            'zustand' => Zustand::Gepflegt,
            'ausstattungsqualitaet' => Ausstattungsqualitaet::Normal,
            'heizungsart' => Heizungsart::Zentralheizung,
            'energietraeger' => Energietraeger::Gas,
            'heizkosten_versorgung' => HeizkostenVersorgung::Zentral,
            'heizung_waermeabgabe' => null,
            'heizung_warmwasser' => null,
            'verfuegbar_ab_typ' => VerfuegbarAbTyp::Sofort,
            'verfuegbar_ab_datum' => null,
            'ausstattung' => [
                'balkon' => true,
                'terrasse' => false,
                'garten' => false,
                'keller' => true,
                'aufzug' => false,
                'einbaukueche' => true,
                'gaeste_wc' => false,
                'barrierefrei' => false,
                'moebliert' => false,
                'wg_geeignet' => false,
                'haustiere_erlaubt' => false,
            ],
            'einbaukueche_mitvermietet' => null,
            'stellplatz_typ' => null,
            'stellplatz_anzahl' => null,
            'beschreibung_objekt' => null,
            'beschreibung_ausstattung' => null,
            'beschreibung_lage' => null,
            'beschreibung_sonstiges' => null,
            'ansprechpartner_user_id' => null,
            'status' => ListingStatus::Entwurf,
            'erstellt_von_user_id' => User::factory(),
        ];
    }

    public function miete(): static
    {
        return $this->state(fn (array $attributes): array => [
            'vermarktungsart' => Vermarktungsart::Miete,
            'heizkosten_versorgung' => $attributes['heizkosten_versorgung'] ?? HeizkostenVersorgung::Zentral,
        ]);
    }

    public function kauf(): static
    {
        return $this->state(fn (array $attributes): array => [
            'vermarktungsart' => Vermarktungsart::Kauf,
            'heizkosten_versorgung' => null,
        ]);
    }

    /**
     * Gewerbeobjekt (Büro): Gewerbe- und Nutzfläche statt Wohnfläche, keine
     * Zimmer (Masterprompt-Abgleich B.2).
     */
    public function gewerbe(GewerbeUnterart $unterart = GewerbeUnterart::Buero): static
    {
        return $this->state(fn (array $attributes): array => [
            'objektart' => Objektart::Gewerbe,
            'gewerbe_unterart' => $unterart,
            'titel' => 'Helle Bürofläche in Erkelenz',
            'wohnflaeche_qm' => null,
            'nutzflaeche_qm' => 120.00,
            'gewerbeflaeche_qm' => 120.00,
            'zimmer' => null,
            'schlafzimmer' => null,
            'badezimmer' => null,
            'ausstattung' => [],
        ]);
    }

    /**
     * Mehrfamilienhaus: Wohnfläche gesamt und Grundstück, keine Etage einer
     * einzelnen Einheit.
     */
    public function mehrfamilienhaus(): static
    {
        return $this->state(fn (array $attributes): array => [
            'objektart' => Objektart::Mehrfamilienhaus,
            'titel' => 'Mehrfamilienhaus mit sechs Einheiten in Erkelenz',
            'wohnflaeche_qm' => 420.00,
            'grundstuecksflaeche_qm' => 650.00,
            'zimmer' => null,
            'schlafzimmer' => null,
            'badezimmer' => null,
            'etage' => null,
            'etagen_gesamt' => 3,
        ]);
    }

    /**
     * Grundstück: nur Grundstücksfläche, kein Energieausweis, keine Heizung.
     */
    public function grundstueck(): static
    {
        return $this->state(fn (array $attributes): array => [
            'objektart' => Objektart::Grundstueck,
            'titel' => 'Baugrundstück in Erkelenz',
            'wohnflaeche_qm' => null,
            'nutzflaeche_qm' => null,
            'grundstuecksflaeche_qm' => 800.00,
            'zimmer' => null,
            'schlafzimmer' => null,
            'badezimmer' => null,
            'etage' => null,
            'etagen_gesamt' => null,
            'baujahr' => null,
            'zustand' => null,
            'ausstattungsqualitaet' => null,
            'heizungsart' => null,
            'energietraeger' => null,
            'ausstattung' => [],
        ]);
    }

    /**
     * Stellplatz: kein Energieausweis, keine Flächen, keine Zimmer.
     */
    public function stellplatz(): static
    {
        return $this->state(fn (array $attributes): array => [
            'objektart' => Objektart::Stellplatz,
            'titel' => 'Tiefgaragenstellplatz in Erkelenz',
            'wohnflaeche_qm' => null,
            'nutzflaeche_qm' => null,
            'grundstuecksflaeche_qm' => null,
            'zimmer' => null,
            'schlafzimmer' => null,
            'badezimmer' => null,
            'etage' => null,
            'etagen_gesamt' => null,
            'baujahr' => null,
            'zustand' => null,
            'ausstattungsqualitaet' => null,
            'heizungsart' => null,
            'energietraeger' => null,
            'ausstattung' => [],
            'stellplatz_typ' => StellplatzTyp::Tiefgarage,
            'stellplatz_anzahl' => 1,
        ]);
    }

    /**
     * Vermietetes Objekt zum Verkauf (Masterprompt-Abgleich B.1 Schritt 1):
     * Kauf mit Nutzungsstatus vermietet und Ist-Mieteinnahmen.
     */
    public function vermietetZumVerkauf(): static
    {
        return $this->kauf()->state(fn (array $attributes): array => [
            'nutzungsstatus' => Nutzungsstatus::Vermietet,
        ])->afterCreating(function (Listing $listing): void {
            $this->erzeugePreise($listing);
            $listing->price()->update(['mieteinnahmen_ist_cent' => 9_600_00]);
        });
    }

    /**
     * Legt eine zum Objekt passende ListingPrice an (Datenvertrag Abschnitt 2.3).
     */
    public function mitPreisen(): static
    {
        return $this->afterCreating(function (Listing $listing): void {
            $this->erzeugePreise($listing);
        });
    }

    /**
     * Legt einen Energieausweis mit Status "liegt vor" an (Datenvertrag Abschnitt 2.4).
     */
    public function mitEnergieausweis(): static
    {
        return $this->afterCreating(function (Listing $listing): void {
            $this->erzeugeEnergieausweis($listing);
        });
    }

    /**
     * Erzeugt ein Objekt, das die Vollständigkeitsprüfung besteht: Preise,
     * Energieausweis, ein Bild fürs Inserat und ein Ansprechpartner.
     */
    public function vollstaendig(): static
    {
        return $this->state(fn (array $attributes): array => [
            'beschreibung_objekt' => 'Diese gepflegte Wohnung überzeugt durch einen hellen Zuschnitt und eine ruhige Lage in Erkelenz.',
            'ansprechpartner_user_id' => User::factory(),
        ])->afterCreating(function (Listing $listing): void {
            $this->erzeugePreise($listing);
            $this->erzeugeEnergieausweis($listing);

            $listing->media()->create([
                'typ' => MediaTyp::Bild,
                'dateiname_original' => 'titelbild.jpg',
                'pfad' => 'listings/'.$listing->uuid.'/titelbild.jpg',
                'mime' => 'image/jpeg',
                'groesse_bytes' => 204_800,
                'breite' => 1600,
                'hoehe' => 1200,
                'sortierung' => 0,
                'titel' => 'Titelbild',
                'im_inserat' => true,
                'pruefsumme_sha256' => hash('sha256', 'titelbild-'.$listing->uuid),
            ]);
        });
    }

    public function archiviert(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ListingStatus::Archiviert,
        ]);
    }

    private function erzeugePreise(Listing $listing): void
    {
        if ($listing->price()->exists()) {
            return;
        }

        if ($listing->vermarktungsart === Vermarktungsart::Kauf) {
            $listing->price()->create([
                'kaufpreis_cent' => 32_500_00,
                'hausgeld_cent' => 25_000,
                'stellplatz_modus' => StellplatzModus::Keiner,
                'provision_typ' => ProvisionTyp::Provisionsfrei,
                'provision_bestaetigt' => false,
            ]);

            return;
        }

        $listing->price()->create([
            'kaltmiete_cent' => 80_000,
            'nebenkosten_cent' => 20_000,
            'heizkosten_cent' => 10_000,
            'heizkosten_in_nebenkosten_enthalten' => false,
            'heizkosten_struktur' => $listing->heizkosten_versorgung === HeizkostenVersorgung::Dezentral
                ? HeizkostenStruktur::EigenerVertrag
                : HeizkostenStruktur::Zusaetzlich,
            'warmmiete_cent' => 110_000,
            'kaution_cent' => 240_000,
            'stellplatz_modus' => StellplatzModus::Keiner,
            'provision_typ' => ProvisionTyp::Provisionsfrei,
            'provision_bestaetigt' => false,
        ]);
    }

    private function erzeugeEnergieausweis(Listing $listing): void
    {
        if ($listing->energy()->exists()) {
            return;
        }

        $listing->energy()->create([
            'status' => EnergieausweisStatus::LiegtVor,
            'ausweistyp' => Ausweistyp::Verbrauch,
            'kennwert_kwh' => 120.5,
            'effizienzklasse' => Effizienzklasse::C,
            'baujahr_anlage' => 2005,
            'enthaelt_warmwasser' => true,
        ]);
    }
}
