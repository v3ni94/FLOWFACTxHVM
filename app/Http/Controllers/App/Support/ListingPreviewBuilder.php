<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

use App\Domain\Listing\ListingSnapshot;
use App\Domain\Listing\Merkmale;
use App\Domain\Listing\PriceStructure;
use App\Domain\Settings\SettingsRepository;
use App\Enums\AdressFreigabe;
use App\Enums\EnergieausweisStatus;
use App\Enums\MediaTyp;
use App\Enums\MerkmalWert;
use App\Enums\Objektart;
use App\Enums\StellplatzModus;
use App\Enums\Vermarktungsart;
use App\Models\Listing;
use App\Models\User;

/**
 * Baut die Datengrundlage für die Vorschau der übertragenen Inhalte auf der
 * Prüfseite (Masterprompt Abschnitt 17, Masterprompt-Abgleich B.1 Schritt 8).
 *
 * Liest ausschließlich App\Domain\Listing\ListingSnapshot::fromListing, nie
 * listing_internals: die Momentaufnahme enthält technisch keine internen
 * Felder (App\Domain\Listing\PublishableFields). Für den öffentlichen
 * Ansprechpartner und die Medien-URLs wird zusätzlich der zugehörige
 * Datensatz anhand der in der Momentaufnahme enthaltenen ID geladen, niemals
 * über eine andere Quelle.
 */
final class ListingPreviewBuilder
{
    public function __construct(
        private readonly SettingsRepository $settings = new SettingsRepository,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Listing $listing): array
    {
        $snapshot = ListingSnapshot::fromListing($listing);
        $daten = $snapshot->listing;

        $vermarktungsart = Vermarktungsart::from($daten['vermarktungsart']);
        $objektart = Objektart::from($daten['objektart']);
        $adressFreigabe = AdressFreigabe::from($daten['adress_freigabe']);

        $medien = collect($snapshot->medien);
        $bilder = $medien->whereIn('typ', [MediaTyp::Bild->value, MediaTyp::Grundriss->value])->values();
        $titelbild = $bilder->firstWhere('typ', MediaTyp::Bild->value);
        $unterlagen = $medien->whereIn('typ', [MediaTyp::Dokument->value, MediaTyp::Energieausweis->value])->values();

        return [
            'titel' => $daten['titel'],
            'vermarktungsart' => $vermarktungsart,
            'objektart' => $objektart,
            'adresse' => $this->adresse($daten, $adressFreigabe),
            'flaechen' => $this->flaechen($daten, $objektart),
            'objektdaten' => $this->objektdaten($daten, $objektart),
            'merkmale' => $this->merkmale($daten, $objektart),
            'preis' => $this->preis($snapshot->price, $vermarktungsart),
            'energie' => $this->energie($snapshot->energy, $objektart),
            'texte' => [
                'beschreibung_objekt' => $daten['beschreibung_objekt'],
                'beschreibung_ausstattung' => $daten['beschreibung_ausstattung'],
                'beschreibung_lage' => $daten['beschreibung_lage'],
                'beschreibung_sonstiges' => $daten['beschreibung_sonstiges'],
            ],
            'bilder' => $bilder,
            'titelbild' => $titelbild,
            'unterlagen' => $unterlagen,
            'ansprechpartner' => $this->ansprechpartner($daten['ansprechpartner_user_id'] ?? null),
            'firma' => [
                'name' => $this->settings->get('firma.name'),
                'strasse' => $this->settings->get('firma.strasse'),
                'plz_ort' => $this->settings->get('firma.plz_ort'),
                'telefon' => $this->settings->get('firma.telefon'),
                'email' => $this->settings->get('firma.email'),
            ],
        ];
    }

    /**
     * Textbestandteile, in denen bei "nur PLZ und Ort" keine Straße oder
     * Hausnummer vorkommen darf (Masterprompt-Abgleich B.7).
     *
     * @return array<string, string|null>
     */
    public function textfelder(Listing $listing): array
    {
        $daten = ListingSnapshot::fromListing($listing)->listing;

        return [
            'titel' => $daten['titel'],
            'beschreibung_objekt' => $daten['beschreibung_objekt'],
            'beschreibung_ausstattung' => $daten['beschreibung_ausstattung'],
            'beschreibung_lage' => $daten['beschreibung_lage'],
            'beschreibung_sonstiges' => $daten['beschreibung_sonstiges'],
        ];
    }

    /**
     * Prüft einen Text auf Straße und Hausnummer, sofern die Adressfreigabe
     * nur PLZ und Ort erlaubt (Masterprompt-Abgleich B.7). Zeichenkettenvergleich,
     * normalisiert (Kleinschreibung, ein Leerzeichen zwischen Wörtern).
     */
    public static function enthaeltAdresse(Listing $listing, ?string $text): bool
    {
        if ($listing->adress_freigabe !== AdressFreigabe::NurPlzOrt || $text === null || trim($text) === '') {
            return false;
        }

        $normalisiert = self::normalisieren($text);
        $strasse = self::normalisieren((string) $listing->strasse);
        $hausnummer = trim((string) $listing->hausnummer);

        if ($strasse !== '' && str_contains($normalisiert, $strasse)) {
            return true;
        }

        return $hausnummer !== '' && preg_match('/(?<![\p{L}\d])'.preg_quote($hausnummer, '/').'(?![\p{L}\d])/u', $text) === 1;
    }

    private static function normalisieren(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    /**
     * @param  array<string, mixed>  $daten
     * @return array<string, string|null>
     */
    private function adresse(array $daten, AdressFreigabe $freigabe): array
    {
        $vollstaendig = $freigabe === AdressFreigabe::Vollstaendig;

        return [
            'strasse' => $vollstaendig ? $daten['strasse'] : null,
            'hausnummer' => $vollstaendig ? $daten['hausnummer'] : null,
            'adresszusatz' => $vollstaendig ? $daten['adresszusatz'] : null,
            'plz' => $daten['plz'],
            'ort' => $daten['ort'],
            'stadtteil' => $daten['stadtteil'],
            'land' => $daten['land'],
            'freigabe' => $freigabe,
        ];
    }

    /**
     * @param  array<string, mixed>  $daten
     * @return array<string, mixed>
     */
    private function flaechen(array $daten, Objektart $objektart): array
    {
        return [
            'wohnflaeche_qm' => $objektart->benoetigt('wohnflaeche') ? $daten['wohnflaeche_qm'] : null,
            'nutzflaeche_qm' => $objektart->benoetigt('nutzflaeche') ? $daten['nutzflaeche_qm'] : null,
            'gewerbeflaeche_qm' => $objektart->benoetigt('gewerbeflaeche') ? $daten['gewerbeflaeche_qm'] : null,
            'grundstuecksflaeche_qm' => $objektart->benoetigt('grundstueck') ? $daten['grundstuecksflaeche_qm'] : null,
            'zimmer' => $objektart->benoetigt('zimmer') ? $daten['zimmer'] : null,
            'schlafzimmer' => $daten['schlafzimmer'],
            'badezimmer' => $daten['badezimmer'],
        ];
    }

    /**
     * @param  array<string, mixed>  $daten
     * @return array<string, mixed>
     */
    private function objektdaten(array $daten, Objektart $objektart): array
    {
        return [
            'gewerbe_unterart' => $daten['gewerbe_unterart'],
            'nutzungsstatus' => $daten['nutzungsstatus'],
            'etage' => $objektart->benoetigt('etage') ? $daten['etage'] : null,
            'etagen_gesamt' => $objektart->benoetigt('etage') ? $daten['etagen_gesamt'] : null,
            'baujahr' => $daten['baujahr'],
            'modernisierungsjahr' => $daten['modernisierungsjahr'],
            'zustand' => $daten['zustand'],
            'ausstattungsqualitaet' => $daten['ausstattungsqualitaet'],
            'heizungsart' => $daten['heizungsart'],
            'energietraeger' => $daten['energietraeger'],
            'heizung_waermeabgabe' => $daten['heizung_waermeabgabe'],
            'heizung_warmwasser' => $daten['heizung_warmwasser'],
            'verfuegbar_ab_typ' => $daten['verfuegbar_ab_typ'],
            'verfuegbar_ab_datum' => $daten['verfuegbar_ab_datum'],
            'stellplatz_typ' => $daten['stellplatz_typ'],
            'stellplatz_anzahl' => $daten['stellplatz_anzahl'],
            'einbaukueche_mitvermietet' => $daten['einbaukueche_mitvermietet'],
        ];
    }

    /**
     * Nur die Merkmale mit dem Wert "ja" (Masterprompt-Abgleich B.1 Schritt
     * 5, B.8): "nein" und "unbekannt" werden im Inserat nicht aufgeführt.
     *
     * @param  array<string, mixed>  $daten
     * @return list<string>
     */
    private function merkmale(array $daten, Objektart $objektart): array
    {
        $ausstattung = is_array($daten['ausstattung'] ?? null) ? $daten['ausstattung'] : [];
        $ergebnis = [];

        foreach (Merkmale::fuerObjektart($objektart) as $schluessel) {
            $wert = array_key_exists($schluessel, $ausstattung) ? $ausstattung[$schluessel] : null;

            if (MerkmalWert::aus($wert) === MerkmalWert::Ja) {
                $ergebnis[] = Merkmale::label($schluessel);
            }
        }

        return $ergebnis;
    }

    /**
     * @param  array<string, mixed>|null  $preis
     * @return array<string, mixed>|null
     */
    private function preis(?array $preis, Vermarktungsart $vermarktungsart): ?array
    {
        if ($preis === null) {
            return null;
        }

        $modus = $preis['stellplatz_modus'] !== null ? StellplatzModus::from($preis['stellplatz_modus']) : StellplatzModus::Keiner;

        $stellplatz = null;

        if ($vermarktungsart === Vermarktungsart::Miete && $preis['warmmiete_cent'] !== null) {
            $stellplatz = PriceStructure::gesamtdarstellung((int) $preis['warmmiete_cent'], $modus, $preis['stellplatz_miete_cent']);
        }

        $kautionMonatsmieten = null;

        if ($preis['kaution_cent'] !== null && $preis['kaltmiete_cent'] !== null && (int) $preis['kaltmiete_cent'] > 0
            && (int) $preis['kaution_cent'] % (int) $preis['kaltmiete_cent'] === 0) {
            $kautionMonatsmieten = intdiv((int) $preis['kaution_cent'], (int) $preis['kaltmiete_cent']);
        }

        return [
            'vermarktungsart' => $vermarktungsart,
            'kaltmiete_cent' => $preis['kaltmiete_cent'],
            'nebenkosten_cent' => $preis['nebenkosten_cent'],
            'heizkosten_cent' => $preis['heizkosten_cent'],
            'heizkosten_struktur' => $preis['heizkosten_struktur'],
            'warmmiete_cent' => $preis['warmmiete_cent'],
            'kaution_cent' => $preis['kaution_cent'],
            'kaution_monatsmieten' => $kautionMonatsmieten,
            'stellplatz_modus' => $modus,
            'stellplatz_miete_cent' => $preis['stellplatz_miete_cent'],
            'stellplatz_hinweis' => $stellplatz['hinweis'] ?? null,
            'kaufpreis_cent' => $preis['kaufpreis_cent'],
            'hausgeld_cent' => $preis['hausgeld_cent'],
            'stellplatz_kaufpreis_cent' => $preis['stellplatz_kaufpreis_cent'],
            'stellplatz_im_kaufpreis' => $preis['stellplatz_im_kaufpreis'],
            'mieteinnahmen_ist_cent' => $preis['mieteinnahmen_ist_cent'],
            'provision_typ' => $preis['provision_typ'],
            'provision_text' => $preis['provision_text'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $energie
     * @return array<string, mixed>|null
     */
    private function energie(?array $energie, Objektart $objektart): ?array
    {
        if (! $objektart->benoetigt('energieausweis') || $energie === null || $energie['status'] === null) {
            return null;
        }

        $status = EnergieausweisStatus::from($energie['status'])->normalisiert();

        return [
            'status' => $status,
            'ausweistyp' => $energie['ausweistyp'],
            'ausstellungsdatum' => $energie['ausstellungsdatum'],
            'kennwert_kwh' => $energie['kennwert_kwh'],
            'kennwert_strom_kwh' => $energie['kennwert_strom_kwh'],
            'effizienzklasse' => $energie['effizienzklasse'],
            'baujahr_anlage' => $energie['baujahr_anlage'],
            'gueltig_bis' => $energie['gueltig_bis'],
            'enthaelt_warmwasser' => $energie['enthaelt_warmwasser'],
        ];
    }

    /**
     * @return array{name: string, phone: string|null, email: string|null}|null
     */
    private function ansprechpartner(mixed $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return null;
        }

        return [
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
        ];
    }
}
