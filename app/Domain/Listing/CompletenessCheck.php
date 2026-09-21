<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Enums\AdressFreigabe;
use App\Enums\MediaTyp;
use App\Enums\MerkmalWert;
use App\Enums\Nutzungsstatus;
use App\Enums\Objektart;
use App\Enums\ProvisionTyp;
use App\Enums\PruefEbene;
use App\Enums\StellplatzModus;
use App\Enums\VerfuegbarAbTyp;
use App\Enums\Vermarktungsart;
use App\Models\Listing;
use App\Models\ListingMedia;

/**
 * Vollständigkeitsprüfung vor "bereit" und vor der Veröffentlichung
 * (Datenvertrag Abschnitt 4.1, Masterprompt-Abgleich B.4).
 *
 * befunde() liefert je Befund Feld, Ebene (intern, flowfact, portal,
 * gesetzlich), Art (blockierend, hinweis) und den Schritt des Assistenten
 * (B.1). check() bleibt als Sicht auf dieselben Befunde erhalten: fehlend
 * enthält die blockierenden Befunde (Feldschlüssel => Label), hinweise die
 * Meldungen der vor Veröffentlichung zu bestätigenden Hinweise (Datenvertrag
 * Abschnitt 3). Rein informative Hinweise (unbekannte Merkmale, fehlende
 * Lagebeschreibung) stehen nur in befunde().
 */
final class CompletenessCheck
{
    public const string HINWEIS_VERMIETET = 'Das Objekt ist vermietet. Es darf in den Texten nicht als bezugsfrei beschrieben werden.';

    public const string HINWEIS_STELLPLATZ_OHNE_MODUS = 'Es ist ein Stellplatzbetrag erfasst, aber der Stellplatzmodus steht auf "kein Stellplatz". Bitte den Modus prüfen.';

    public function __construct(
        private readonly RentCalculator $rentCalculator = new RentCalculator,
        private readonly EnergyRequirements $energyRequirements = new EnergyRequirements,
    ) {}

    public function check(Listing $listing): CompletenessResult
    {
        $fehlend = [];
        $hinweise = [];

        foreach ($this->befunde($listing) as $befund) {
            if ($befund->istBlockierend()) {
                $fehlend[$befund->feld] ??= $befund->label;

                continue;
            }

            if ($befund->bestaetigungspflichtig) {
                $hinweise[] = $befund->meldung;
            }
        }

        return new CompletenessResult($fehlend, array_values(array_unique($hinweise)));
    }

    public function blockiert(Listing $listing): bool
    {
        foreach ($this->befunde($listing) as $befund) {
            if ($befund->istBlockierend()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Befund>
     */
    public function befunde(Listing $listing): array
    {
        $befunde = [];

        $this->pruefeGrunddaten($listing, $befunde);
        $this->pruefeAdresse($listing, $befunde);
        $this->pruefeFlaechenUndZimmer($listing, $befunde);
        $this->pruefePreise($listing, $befunde);
        $this->pruefeMerkmale($listing, $befunde);

        foreach ($this->energyRequirements->pruefe($listing) as $befund) {
            $befunde[] = $befund;
        }

        $this->pruefeMedien($listing, $befunde);
        $this->pruefeTexte($listing, $befunde);

        return $befunde;
    }

    /**
     * @param  list<Befund>  $befunde
     */
    private function pruefeGrunddaten(Listing $listing, array &$befunde): void
    {
        if ($listing->ansprechpartner_user_id === null) {
            $befunde[] = Befund::blockierend('ansprechpartner_user_id', 'Ansprechpartner', 1);
        }

        if ($listing->flowfact_schema === null) {
            $befunde[] = Befund::blockierend('flowfact_schema', 'FLOWFACT-Schema', 1, null, PruefEbene::Flowfact);
        }

        if ($listing->verfuegbar_ab_typ === null) {
            $befunde[] = Befund::blockierend('verfuegbar_ab_typ', 'Verfügbarkeit', 1);
        } elseif ($listing->verfuegbar_ab_typ === VerfuegbarAbTyp::Datum && $listing->verfuegbar_ab_datum === null) {
            $befunde[] = Befund::blockierend('verfuegbar_ab_datum', 'Verfügbar ab (Datum)', 1);
        }

        if ($listing->vermarktungsart === Vermarktungsart::Kauf && $listing->nutzungsstatus === Nutzungsstatus::Vermietet) {
            $befunde[] = Befund::hinweis('nutzungsstatus', 'Nutzungsstatus', 1, self::HINWEIS_VERMIETET, bestaetigungspflichtig: true);
        }
    }

    /**
     * @param  list<Befund>  $befunde
     */
    private function pruefeAdresse(Listing $listing, array &$befunde): void
    {
        foreach (['strasse' => 'Straße', 'hausnummer' => 'Hausnummer', 'plz' => 'Postleitzahl', 'ort' => 'Ort'] as $feld => $label) {
            if (empty($listing->{$feld})) {
                $befunde[] = Befund::blockierend($feld, $label, 2);
            }
        }
    }

    /**
     * Feldgruppen je Objektart (Objektart::benoetigt, Masterprompt-Abgleich B.1 Schritt 3).
     *
     * @param  list<Befund>  $befunde
     */
    private function pruefeFlaechenUndZimmer(Listing $listing, array &$befunde): void
    {
        $objektart = $listing->objektart;

        if ($objektart->benoetigt('wohnflaeche') && $listing->wohnflaeche_qm === null) {
            $befunde[] = Befund::blockierend('wohnflaeche_qm', 'Wohnfläche', 3);
        }

        if ($objektart->benoetigt('zimmer') && $listing->zimmer === null) {
            $befunde[] = Befund::blockierend('zimmer', 'Zimmer', 3);
        }

        if ($objektart->benoetigt('grundstueck') && $listing->grundstuecksflaeche_qm === null) {
            $befunde[] = Befund::blockierend('grundstuecksflaeche_qm', 'Grundstücksfläche', 3);
        }

        // Gewerbe: Gewerbefläche, ersatzweise die Nutzfläche des bisherigen
        // Assistenten (Datenvertrag Abschnitt 2.2).
        if ($objektart->benoetigt('gewerbeflaeche') && $listing->gewerbeflaeche_qm === null && $listing->nutzflaeche_qm === null) {
            $befunde[] = Befund::blockierend('gewerbeflaeche_qm', 'Gewerbefläche', 3, 'Gewerbefläche oder Nutzfläche fehlt.');
        }
    }

    /**
     * @param  list<Befund>  $befunde
     */
    private function pruefePreise(Listing $listing, array &$befunde): void
    {
        $preis = $listing->price;

        if ($listing->vermarktungsart === Vermarktungsart::Miete) {
            $struktur = PriceStructure::ermittle($listing);

            if ($struktur === null) {
                $befunde[] = Befund::blockierend('heizkosten_versorgung', 'Heizkostenstruktur', 4, 'Die Kostenstruktur der Heizkosten fehlt (enthalten, zusätzlich oder eigener Versorgungsvertrag).');
            }

            if ($preis === null || $preis->kaltmiete_cent === null) {
                $befunde[] = Befund::blockierend('preis.kaltmiete_cent', 'Kaltmiete', 4);
            }

            if ($preis === null || $preis->nebenkosten_cent === null) {
                $befunde[] = Befund::blockierend('preis.nebenkosten_cent', 'Nebenkosten', 4);
            }

            if ($preis !== null && $preis->kaltmiete_cent !== null && $preis->nebenkosten_cent !== null && $struktur !== null) {
                try {
                    $ergebnis = $this->rentCalculator->calculate(
                        kaltmieteCent: $preis->kaltmiete_cent,
                        nebenkostenCent: $preis->nebenkosten_cent,
                        heizkostenCent: $preis->heizkosten_cent,
                        heizkostenInNebenkostenEnthalten: PriceStructure::heizkostenEnthalten($struktur),
                        versorgung: PriceStructure::versorgung($struktur),
                    );

                    foreach ($ergebnis->hinweise as $hinweis) {
                        $befunde[] = Befund::hinweis('preis.warmmiete_cent', 'Warmmiete', 4, $hinweis->label(), bestaetigungspflichtig: true);
                    }
                } catch (InvalidRentInputException) {
                    // Prüfbericht 2026-09-11, Befund 2: widersprüchliche
                    // Preisangaben dürfen die Prüfung nie mit einer Ausnahme
                    // abbrechen, sondern werden als blockierender Befund gemeldet.
                    $befunde[] = Befund::blockierend('preis.widerspruch', 'Preisangaben widersprüchlich', 4, 'Die Preisangaben widersprechen der Heizkostenstruktur. Bitte die Preise erneut speichern.');
                }
            }
        }

        if ($listing->vermarktungsart === Vermarktungsart::Kauf && ($preis === null || $preis->kaufpreis_cent === null)) {
            $befunde[] = Befund::blockierend('preis.kaufpreis_cent', 'Kaufpreis', 4);
        }

        if ($preis === null) {
            return;
        }

        if ($preis->provision_typ === ProvisionTyp::Provisionspflichtig) {
            if (empty($preis->provision_text)) {
                $befunde[] = Befund::blockierend('preis.provision_text', 'Provisionstext', 4);
            }

            if (! $preis->provision_bestaetigt) {
                $befunde[] = Befund::blockierend('preis.provision_bestaetigt', 'Provisionsbestätigung', 4, 'Die Provisionsangabe muss vor der Veröffentlichung ausdrücklich bestätigt werden.');
            }
        }

        $this->pruefeStellplatz($listing, $befunde);
    }

    /**
     * @param  list<Befund>  $befunde
     */
    private function pruefeStellplatz(Listing $listing, array &$befunde): void
    {
        $preis = $listing->price;
        $modus = $preis->stellplatz_modus ?? StellplatzModus::Keiner;
        $istMiete = $listing->vermarktungsart === Vermarktungsart::Miete;

        try {
            PriceStructure::pruefeStellplatz($modus, $preis->stellplatz_miete_cent, $preis->stellplatz_kaufpreis_cent);
        } catch (InvalidRentInputException $exception) {
            $befunde[] = Befund::blockierend('preis.stellplatz_widerspruch', 'Stellplatzangaben widersprüchlich', 4, $exception->getMessage());

            return;
        }

        if ($modus->verlangtBetrag()) {
            if ($istMiete && ($preis->stellplatz_miete_cent === null || $preis->stellplatz_miete_cent <= 0)) {
                $befunde[] = Befund::blockierend('preis.stellplatz_miete_cent', 'Stellplatzmiete', 4, 'Für den gewählten Stellplatzmodus fehlt die Stellplatzmiete.');
            }

            if (! $istMiete && ($preis->stellplatz_kaufpreis_cent === null || $preis->stellplatz_kaufpreis_cent <= 0)) {
                $befunde[] = Befund::blockierend('preis.stellplatz_kaufpreis_cent', 'Stellplatzkaufpreis', 4, 'Für den gewählten Stellplatzmodus fehlt der Stellplatzkaufpreis.');
            }

            return;
        }

        if ($modus === StellplatzModus::Keiner && PriceStructure::stellplatzBetragErfasst($preis->stellplatz_miete_cent, $preis->stellplatz_kaufpreis_cent)) {
            $befunde[] = Befund::hinweis('preis.stellplatz_modus', 'Stellplatzmodus', 4, self::HINWEIS_STELLPLATZ_OHNE_MODUS);
        }
    }

    /**
     * Unbekannte Merkmale sind nur Hinweise (Masterprompt-Abgleich B.4), als
     * ein gesammelter Befund je Objekt.
     *
     * @param  list<Befund>  $befunde
     */
    private function pruefeMerkmale(Listing $listing, array &$befunde): void
    {
        $unbekannt = [];

        foreach ($listing->merkmale() as $schluessel => $wert) {
            if ($wert === MerkmalWert::Unbekannt) {
                $unbekannt[] = Merkmale::label($schluessel);
            }
        }

        if ($unbekannt !== []) {
            $befunde[] = Befund::hinweis('ausstattung', 'Ausstattungsmerkmale', 5, 'Merkmale ohne Angabe: '.implode(', ', $unbekannt).'. Sie werden weder als ja noch als nein übertragen.');
        }
    }

    /**
     * Mindestens ein freigegebenes Bild im Inserat. Nicht freigegebene
     * Dokumente bleiben unberücksichtigt.
     *
     * @param  list<Befund>  $befunde
     */
    private function pruefeMedien(Listing $listing, array &$befunde): void
    {
        $hatBild = $listing->media->contains(
            fn (ListingMedia $medium): bool => $medium->typ === MediaTyp::Bild && $medium->istVeroeffentlichbar(),
        );

        if (! $hatBild) {
            $befunde[] = Befund::blockierend('medien.bild', 'Mindestens ein Bild für das Inserat', 6, 'Es fehlt mindestens ein freigegebenes Bild für das Inserat.');
        }

        // Prüfbericht 2026-09-12, Befund 4: Bildtitel veröffentlichbarer
        // Medien müssen ebenso auf die Adresse geprüft werden wie die Texte,
        // sonst erreicht die ausgeblendete Adresse FLOWFACT und die Portale
        // über die Bildbeschriftung.
        if ($listing->adress_freigabe === AdressFreigabe::NurPlzOrt) {
            foreach ($listing->media as $medium) {
                if ($medium->istVeroeffentlichbar() && $this->titelEnthaeltAdresse($listing, $medium->titel)) {
                    $befunde[] = Befund::blockierend(
                        'medien.titel',
                        'Bildtitel',
                        6,
                        'Der Bildtitel "'.$medium->titel.'" enthält Straße oder Hausnummer, obwohl die Adressfreigabe nur PLZ und Ort erlaubt.',
                        PruefEbene::Portal,
                    );
                }
            }
        }
    }

    /**
     * Adressprüfung eines Medientitels, spiegelbildlich zu
     * App\Http\Controllers\App\Support\ListingPreviewBuilder::enthaeltAdresse
     * (Masterprompt-Abgleich B.7). Bewusst als eigene Domänenfunktion ohne
     * Abhängigkeit auf die HTTP-Schicht gehalten.
     */
    private function titelEnthaeltAdresse(Listing $listing, ?string $titel): bool
    {
        if ($titel === null || trim($titel) === '') {
            return false;
        }

        $normalisiere = static fn (string $text): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));

        $normalisiert = $normalisiere($titel);
        $strasse = $normalisiere((string) $listing->strasse);
        $hausnummer = trim((string) $listing->hausnummer);

        if ($strasse !== '' && str_contains($normalisiert, $strasse)) {
            return true;
        }

        return $hausnummer !== '' && preg_match('/(?<![\p{L}\d])'.preg_quote($hausnummer, '/').'(?![\p{L}\d])/u', $titel) === 1;
    }

    /**
     * @param  list<Befund>  $befunde
     */
    private function pruefeTexte(Listing $listing, array &$befunde): void
    {
        if (empty($listing->titel)) {
            $befunde[] = Befund::blockierend('titel', 'Titel', 7);
        }

        if (empty($listing->beschreibung_objekt)) {
            $befunde[] = Befund::blockierend('beschreibung_objekt', 'Objektbeschreibung', 8);
        }

        if (empty($listing->beschreibung_lage)) {
            $befunde[] = Befund::hinweis('beschreibung_lage', 'Lagebeschreibung', 8, 'Die Lagebeschreibung fehlt.');
        }
    }
}
