<?php

declare(strict_types=1);

namespace App\Flowfact\Mapping;

use App\Enums\Ausstattungsqualitaet;
use App\Enums\Ausweistyp;
use App\Enums\Effizienzklasse;
use App\Enums\EnergieausweisStatus;
use App\Enums\Energietraeger;
use App\Enums\GewerbeUnterart;
use App\Enums\HeizkostenStruktur;
use App\Enums\HeizkostenVersorgung;
use App\Enums\Heizungsart;
use App\Enums\Nutzungsstatus;
use App\Enums\Objektart;
use App\Enums\ProvisionTyp;
use App\Enums\StellplatzModus;
use App\Enums\StellplatzTyp;
use App\Enums\VerfuegbarAbTyp;
use App\Enums\Waermeabgabe;
use App\Enums\Warmwasserbereitung;
use App\Enums\Zustand;
use BackedEnum;

/**
 * Standardzuordnung eigener Inseratsfelder auf FLOWFACT-Feldnamen und Codes
 * (docs/connector.md Abschnitt 4.1 und 4.3).
 *
 * Schlüssel sind ausschließlich Felder aus PublishableFields: Objektfelder
 * ohne Präfix, Preisfelder ohne Präfix, Energiefelder mit Präfix "energie.",
 * Ausstattungsmerkmale mit Präfix "ausstattung.". Felder aus
 * listing_internals haben keinen Eintrag und können keinen bekommen.
 *
 * ziel = null bedeutet: ohne bestätigten Standard, über die Einstellung
 * flowfact.feldzuordnung setzbar. Art ABSICHTLICH bedeutet: bewusst nicht
 * übertragen (kein Warnhinweis).
 */
final class FieldCatalog
{
    public const string TEXT = 'text';

    public const string ZAHL = 'zahl';

    public const string EURO = 'euro';

    public const string BOOL = 'bool';

    public const string CODE = 'code';

    public const string DATUM = 'datum';

    public const string ADRESSE = 'adresse';

    public const string ADRESSTEIL = 'adressteil';

    public const string AUSSTATTUNG = 'ausstattung';

    public const string ABSICHTLICH = 'absichtlich';

    /**
     * Pseudo-Feld für die zusammengesetzte Adresse. Kein Positivlistenfeld,
     * liefert nur den Zielfeldnamen; die Bestandteile stammen aus der
     * Positivliste (strasse, hausnummer, plz, ort, land).
     */
    public const string ADRESSFELD = 'adresse';

    /**
     * @var list<string>
     */
    public const array AUSSTATTUNG_SCHLUESSEL = [
        'balkon', 'terrasse', 'garten', 'keller', 'aufzug', 'einbaukueche',
        'gaeste_wc', 'barrierefrei', 'moebliert', 'wg_geeignet', 'haustiere_erlaubt',
    ];

    /**
     * @var array<string, array{ziel: string|null, label: string, art: string, gruppe?: string, bereich: string}>
     */
    public const array FELDER = [
        // Objektfelder (listings)
        'uuid' => ['ziel' => null, 'label' => 'UUID', 'art' => self::ABSICHTLICH, 'bereich' => 'listing'],
        'objektnummer' => ['ziel' => 'identifier', 'label' => 'Objektnummer', 'art' => self::TEXT, 'bereich' => 'listing'],
        'vermarktungsart' => ['ziel' => null, 'label' => 'Vermarktungsart', 'art' => self::ABSICHTLICH, 'bereich' => 'listing'],
        'objektart' => ['ziel' => 'estatetype', 'label' => 'Objektart', 'art' => self::CODE, 'gruppe' => 'objektart', 'bereich' => 'listing'],
        // Masterprompt-Abgleich B.2 (Welle 1): neue Inseratsfelder ohne bestätigte Zuordnung, Zuordnung folgt in Welle 3.
        'gewerbe_unterart' => ['ziel' => null, 'label' => 'Gewerbe-Unterart', 'art' => self::CODE, 'gruppe' => 'gewerbe_unterart', 'bereich' => 'listing'],
        'nutzungsstatus' => ['ziel' => null, 'label' => 'Nutzungsstatus', 'art' => self::CODE, 'gruppe' => 'nutzungsstatus', 'bereich' => 'listing'],
        'adresszusatz' => ['ziel' => null, 'label' => 'Adresszusatz', 'art' => self::TEXT, 'bereich' => 'listing'],
        'stadtteil' => ['ziel' => null, 'label' => 'Stadtteil', 'art' => self::TEXT, 'bereich' => 'listing'],
        // Ableitungsquelle von adresse_im_inserat_anzeigen, wirkt über showAddress.
        'adress_freigabe' => ['ziel' => null, 'label' => 'Adressfreigabe', 'art' => self::ABSICHTLICH, 'bereich' => 'listing'],
        'gewerbeflaeche_qm' => ['ziel' => null, 'label' => 'Gewerbefläche', 'art' => self::ZAHL, 'bereich' => 'listing'],
        'modernisierungsjahr' => ['ziel' => null, 'label' => 'Modernisierungsjahr', 'art' => self::ZAHL, 'bereich' => 'listing'],
        'heizung_waermeabgabe' => ['ziel' => null, 'label' => 'Wärmeabgabe', 'art' => self::CODE, 'gruppe' => 'heizung_waermeabgabe', 'bereich' => 'listing'],
        'heizung_warmwasser' => ['ziel' => null, 'label' => 'Warmwasserbereitung', 'art' => self::CODE, 'gruppe' => 'heizung_warmwasser', 'bereich' => 'listing'],
        'einbaukueche_mitvermietet' => ['ziel' => null, 'label' => 'Einbauküche mitvermietet', 'art' => self::BOOL, 'bereich' => 'listing'],
        'titel' => ['ziel' => 'headline', 'label' => 'Titel', 'art' => self::TEXT, 'bereich' => 'listing'],
        self::ADRESSFELD => ['ziel' => 'addresses', 'label' => 'Adresse (Straße, Hausnummer, PLZ, Ort, Land)', 'art' => self::ADRESSE, 'bereich' => 'listing'],
        'strasse' => ['ziel' => null, 'label' => 'Straße', 'art' => self::ADRESSTEIL, 'bereich' => 'listing'],
        'hausnummer' => ['ziel' => null, 'label' => 'Hausnummer', 'art' => self::ADRESSTEIL, 'bereich' => 'listing'],
        'plz' => ['ziel' => null, 'label' => 'Postleitzahl', 'art' => self::ADRESSTEIL, 'bereich' => 'listing'],
        'ort' => ['ziel' => null, 'label' => 'Ort', 'art' => self::ADRESSTEIL, 'bereich' => 'listing'],
        'land' => ['ziel' => null, 'label' => 'Land', 'art' => self::ADRESSTEIL, 'bereich' => 'listing'],
        'adresse_im_inserat_anzeigen' => ['ziel' => null, 'label' => 'Adresse im Inserat anzeigen', 'art' => self::ABSICHTLICH, 'bereich' => 'listing'],
        'wohnflaeche_qm' => ['ziel' => 'livingarea', 'label' => 'Wohnfläche', 'art' => self::ZAHL, 'bereich' => 'listing'],
        'nutzflaeche_qm' => ['ziel' => 'commercialarea', 'label' => 'Nutzfläche', 'art' => self::ZAHL, 'bereich' => 'listing'],
        'grundstuecksflaeche_qm' => ['ziel' => 'plotarea', 'label' => 'Grundstücksfläche', 'art' => self::ZAHL, 'bereich' => 'listing'],
        'zimmer' => ['ziel' => 'rooms', 'label' => 'Zimmer', 'art' => self::ZAHL, 'bereich' => 'listing'],
        'schlafzimmer' => ['ziel' => 'numberbedrooms', 'label' => 'Schlafzimmer', 'art' => self::ZAHL, 'bereich' => 'listing'],
        'badezimmer' => ['ziel' => 'numberbathrooms', 'label' => 'Badezimmer', 'art' => self::ZAHL, 'bereich' => 'listing'],
        'etage' => ['ziel' => 'floor', 'label' => 'Etage', 'art' => self::ZAHL, 'bereich' => 'listing'],
        'etagen_gesamt' => ['ziel' => 'no_of_floors', 'label' => 'Etagen gesamt', 'art' => self::ZAHL, 'bereich' => 'listing'],
        'baujahr' => ['ziel' => 'yearofconstruction', 'label' => 'Baujahr', 'art' => self::ZAHL, 'bereich' => 'listing'],
        'zustand' => ['ziel' => 'condition', 'label' => 'Zustand', 'art' => self::CODE, 'gruppe' => 'zustand', 'bereich' => 'listing'],
        'ausstattungsqualitaet' => ['ziel' => null, 'label' => 'Ausstattungsqualität', 'art' => self::CODE, 'gruppe' => 'ausstattungsqualitaet', 'bereich' => 'listing'],
        'heizungsart' => ['ziel' => null, 'label' => 'Heizungsart', 'art' => self::CODE, 'gruppe' => 'heizungsart', 'bereich' => 'listing'],
        'energietraeger' => ['ziel' => null, 'label' => 'Energieträger', 'art' => self::CODE, 'gruppe' => 'energietraeger', 'bereich' => 'listing'],
        'heizkosten_versorgung' => ['ziel' => null, 'label' => 'Heizkostenversorgung', 'art' => self::CODE, 'gruppe' => 'heizkosten_versorgung', 'bereich' => 'listing'],
        'verfuegbar_ab_typ' => ['ziel' => null, 'label' => 'Verfügbar ab (Art)', 'art' => self::CODE, 'gruppe' => 'verfuegbar_ab_typ', 'bereich' => 'listing'],
        'verfuegbar_ab_datum' => ['ziel' => null, 'label' => 'Verfügbar ab (Datum)', 'art' => self::DATUM, 'bereich' => 'listing'],
        'ausstattung' => ['ziel' => null, 'label' => 'Ausstattung', 'art' => self::AUSSTATTUNG, 'bereich' => 'listing'],
        'ausstattung.balkon' => ['ziel' => 'balconyavailable', 'label' => 'Ausstattung: Balkon', 'art' => self::BOOL, 'bereich' => 'listing'],
        'ausstattung.terrasse' => ['ziel' => null, 'label' => 'Ausstattung: Terrasse', 'art' => self::BOOL, 'bereich' => 'listing'],
        'ausstattung.garten' => ['ziel' => null, 'label' => 'Ausstattung: Garten', 'art' => self::BOOL, 'bereich' => 'listing'],
        'ausstattung.keller' => ['ziel' => 'cellar', 'label' => 'Ausstattung: Keller', 'art' => self::BOOL, 'bereich' => 'listing'],
        'ausstattung.aufzug' => ['ziel' => 'elevator', 'label' => 'Ausstattung: Aufzug', 'art' => self::BOOL, 'bereich' => 'listing'],
        'ausstattung.einbaukueche' => ['ziel' => null, 'label' => 'Ausstattung: Einbauküche', 'art' => self::BOOL, 'bereich' => 'listing'],
        'ausstattung.gaeste_wc' => ['ziel' => 'guesttoilet', 'label' => 'Ausstattung: Gäste-WC', 'art' => self::BOOL, 'bereich' => 'listing'],
        'ausstattung.barrierefrei' => ['ziel' => 'barrierfree', 'label' => 'Ausstattung: Barrierefrei', 'art' => self::BOOL, 'bereich' => 'listing'],
        'ausstattung.moebliert' => ['ziel' => null, 'label' => 'Ausstattung: Möbliert', 'art' => self::BOOL, 'bereich' => 'listing'],
        'ausstattung.wg_geeignet' => ['ziel' => null, 'label' => 'Ausstattung: WG-geeignet', 'art' => self::BOOL, 'bereich' => 'listing'],
        'ausstattung.haustiere_erlaubt' => ['ziel' => null, 'label' => 'Ausstattung: Haustiere erlaubt', 'art' => self::BOOL, 'bereich' => 'listing'],
        'stellplatz_typ' => ['ziel' => 'parking', 'label' => 'Stellplatztyp', 'art' => self::CODE, 'gruppe' => 'stellplatz_typ', 'bereich' => 'listing'],
        'stellplatz_anzahl' => ['ziel' => null, 'label' => 'Stellplatzanzahl', 'art' => self::ZAHL, 'bereich' => 'listing'],
        'beschreibung_objekt' => ['ziel' => null, 'label' => 'Objektbeschreibung', 'art' => self::TEXT, 'bereich' => 'listing'],
        'beschreibung_ausstattung' => ['ziel' => null, 'label' => 'Ausstattungsbeschreibung', 'art' => self::TEXT, 'bereich' => 'listing'],
        'beschreibung_lage' => ['ziel' => null, 'label' => 'Lagebeschreibung', 'art' => self::TEXT, 'bereich' => 'listing'],
        'beschreibung_sonstiges' => ['ziel' => null, 'label' => 'Sonstiges', 'art' => self::TEXT, 'bereich' => 'listing'],
        // Der Ansprechpartner wird bewusst nicht übertragen: FLOWFACT verknüpft
        // Kontakte über eigene Entitäten, nicht über ein Estate-Feld.
        'ansprechpartner_user_id' => ['ziel' => null, 'label' => 'Ansprechpartner', 'art' => self::ABSICHTLICH, 'bereich' => 'listing'],

        // Preisfelder (listing_prices)
        'kaltmiete_cent' => ['ziel' => 'rent', 'label' => 'Kaltmiete', 'art' => self::EURO, 'bereich' => 'price'],
        'nebenkosten_cent' => ['ziel' => null, 'label' => 'Nebenkosten', 'art' => self::EURO, 'bereich' => 'price'],
        'heizkosten_cent' => ['ziel' => null, 'label' => 'Heizkosten', 'art' => self::EURO, 'bereich' => 'price'],
        'heizkosten_in_nebenkosten_enthalten' => ['ziel' => null, 'label' => 'Heizkosten in Nebenkosten enthalten', 'art' => self::BOOL, 'bereich' => 'price'],
        'warmmiete_cent' => ['ziel' => null, 'label' => 'Warmmiete', 'art' => self::EURO, 'bereich' => 'price'],
        'kaution_cent' => ['ziel' => null, 'label' => 'Kaution', 'art' => self::EURO, 'bereich' => 'price'],
        'stellplatz_miete_cent' => ['ziel' => null, 'label' => 'Stellplatzmiete', 'art' => self::EURO, 'bereich' => 'price'],
        'kaufpreis_cent' => ['ziel' => 'purchaseprice', 'label' => 'Kaufpreis', 'art' => self::EURO, 'bereich' => 'price'],
        'hausgeld_cent' => ['ziel' => null, 'label' => 'Hausgeld', 'art' => self::EURO, 'bereich' => 'price'],
        'stellplatz_kaufpreis_cent' => ['ziel' => null, 'label' => 'Stellplatzkaufpreis', 'art' => self::EURO, 'bereich' => 'price'],
        'mieteinnahmen_ist_cent' => ['ziel' => null, 'label' => 'Mieteinnahmen (Ist, jährlich)', 'art' => self::EURO, 'bereich' => 'price'],
        'provision_typ' => ['ziel' => null, 'label' => 'Provisionstyp', 'art' => self::CODE, 'gruppe' => 'provision_typ', 'bereich' => 'price'],
        'provision_text' => ['ziel' => null, 'label' => 'Provisionstext', 'art' => self::TEXT, 'bereich' => 'price'],
        'heizkosten_struktur' => ['ziel' => null, 'label' => 'Heizkostenstruktur', 'art' => self::CODE, 'gruppe' => 'heizkosten_struktur', 'bereich' => 'price'],
        'stellplatz_modus' => ['ziel' => null, 'label' => 'Stellplatzmodus', 'art' => self::CODE, 'gruppe' => 'stellplatz_modus', 'bereich' => 'price'],
        'stellplatz_im_kaufpreis' => ['ziel' => null, 'label' => 'Stellplatz im Kaufpreis', 'art' => self::BOOL, 'bereich' => 'price'],

        // Energieausweis (listing_energies)
        'energie.status' => ['ziel' => null, 'label' => 'Energieausweis: Status', 'art' => self::CODE, 'gruppe' => 'energie.status', 'bereich' => 'energy'],
        'energie.ausweistyp' => ['ziel' => null, 'label' => 'Energieausweis: Ausweistyp', 'art' => self::CODE, 'gruppe' => 'energie.ausweistyp', 'bereich' => 'energy'],
        'energie.kennwert_kwh' => ['ziel' => null, 'label' => 'Energieausweis: Kennwert', 'art' => self::ZAHL, 'bereich' => 'energy'],
        'energie.effizienzklasse' => ['ziel' => 'energyefficienceclass', 'label' => 'Energieausweis: Effizienzklasse', 'art' => self::CODE, 'gruppe' => 'effizienzklasse', 'bereich' => 'energy'],
        'energie.baujahr_anlage' => ['ziel' => null, 'label' => 'Energieausweis: Baujahr Anlage', 'art' => self::ZAHL, 'bereich' => 'energy'],
        'energie.gueltig_bis' => ['ziel' => null, 'label' => 'Energieausweis: Gültig bis', 'art' => self::DATUM, 'bereich' => 'energy'],
        'energie.enthaelt_warmwasser' => ['ziel' => null, 'label' => 'Energieausweis: Enthält Warmwasser', 'art' => self::BOOL, 'bereich' => 'energy'],
        'energie.ausstellungsdatum' => ['ziel' => null, 'label' => 'Energieausweis: Ausstellungsdatum', 'art' => self::DATUM, 'bereich' => 'energy'],
        'energie.kennwert_strom_kwh' => ['ziel' => null, 'label' => 'Energieausweis: Kennwert Strom', 'art' => self::ZAHL, 'bereich' => 'energy'],
    ];

    /**
     * Bestätigte Codes (docs/connector.md 4.3). null = offen, am Konto zu ermitteln.
     *
     * @var array<string, array<string, string|null>>
     */
    public const array CODES = [
        'objektart' => [
            'wohnung' => '01ETAG',   // Etagenwohnung als Standard, änderbar
            'haus' => '02EFH',
            'gewerbe' => '06B',      // Bürofläche als Standard, änderbar
            'grundstueck' => '03BE',
            'stellplatz' => null,    // OFFEN: im SDK-Auszug kein Code, über Schemaabfrage ermitteln
        ],
        'zustand' => [
            'erstbezug' => '01',
            'neuwertig' => '03',
            'modernisiert' => '05',
            'gepflegt' => '07',
            'renovierungsbeduerftig' => '08',
            'projektiert' => '10',
            'saniert' => '06',       // UNBESTÄTIGT: Vorschlag "renoviert", zu verifizieren
        ],
        'effizienzklasse' => [
            'A+' => '01', 'A' => '02', 'B' => '03', 'C' => '04', 'D' => '05',
            'E' => '06', 'F' => '07', 'G' => '08', 'H' => '09',
        ],
        'stellplatz_typ' => [
            'garage' => '2',
            'aussenstellplatz' => '3',
            'carport' => '4',
            'duplex' => '5',
            'tiefgarage' => '7',
            'keiner' => '1',
        ],
    ];

    /**
     * Codes, die im SDK nicht belegt sind und am Konto zu verifizieren sind.
     *
     * @var list<string>
     */
    public const array UNBESTAETIGTE_CODES = [
        'objektart.stellplatz',
        'zustand.saniert',
    ];

    /**
     * @var array<string, class-string<BackedEnum>>
     */
    public const array CODE_ENUMS = [
        'objektart' => Objektart::class,
        'zustand' => Zustand::class,
        'effizienzklasse' => Effizienzklasse::class,
        'stellplatz_typ' => StellplatzTyp::class,
        'ausstattungsqualitaet' => Ausstattungsqualitaet::class,
        'heizungsart' => Heizungsart::class,
        'energietraeger' => Energietraeger::class,
        'heizkosten_versorgung' => HeizkostenVersorgung::class,
        'verfuegbar_ab_typ' => VerfuegbarAbTyp::class,
        'provision_typ' => ProvisionTyp::class,
        'energie.status' => EnergieausweisStatus::class,
        'energie.ausweistyp' => Ausweistyp::class,
        'gewerbe_unterart' => GewerbeUnterart::class,
        'nutzungsstatus' => Nutzungsstatus::class,
        'heizung_waermeabgabe' => Waermeabgabe::class,
        'heizung_warmwasser' => Warmwasserbereitung::class,
        'heizkosten_struktur' => HeizkostenStruktur::class,
        'stellplatz_modus' => StellplatzModus::class,
    ];

    /**
     * Alle Codegruppen: bestätigte Codes plus Enum-Werte ohne Standard.
     *
     * @return array<string, array<string, string|null>>
     */
    public static function codeGruppen(): array
    {
        $gruppen = [];

        foreach (self::CODE_ENUMS as $gruppe => $enum) {
            $werte = [];

            foreach ($enum::cases() as $fall) {
                $werte[(string) $fall->value] = self::CODES[$gruppe][$fall->value] ?? null;
            }

            $gruppen[$gruppe] = $werte;
        }

        return $gruppen;
    }

    public static function codeLabel(string $gruppe, string $wert): string
    {
        $enum = self::CODE_ENUMS[$gruppe] ?? null;

        if ($enum === null) {
            return $wert;
        }

        $fall = $enum::tryFrom($wert);

        return $fall !== null && method_exists($fall, 'label') ? $fall->label() : $wert;
    }

    public static function gruppenLabel(string $gruppe): string
    {
        foreach (self::FELDER as $definition) {
            if (($definition['gruppe'] ?? null) === $gruppe) {
                return $definition['label'];
            }
        }

        return $gruppe;
    }

    public static function label(string $feld): string
    {
        return self::FELDER[$feld]['label'] ?? $feld;
    }
}
