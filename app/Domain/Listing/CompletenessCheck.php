<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Enums\MediaTyp;
use App\Enums\Objektart;
use App\Enums\ProvisionTyp;
use App\Enums\VerfuegbarAbTyp;
use App\Enums\Vermarktungsart;
use App\Models\Listing;

/**
 * Vollständigkeitsprüfung vor dem Statuswechsel nach "bereit"
 * (Datenvertrag Abschnitt 4.1: Titel, Adresse, Flächen und Zimmer je
 * Objektart, Preise je Vermarktungsart, Energieausweisstatus, mindestens ein
 * Bild, Beschreibung, Ansprechpartner).
 */
final class CompletenessCheck
{
    public function __construct(
        private readonly RentCalculator $rentCalculator = new RentCalculator,
    ) {}

    public function check(Listing $listing): CompletenessResult
    {
        $fehlend = [];
        $hinweise = [];

        $this->pruefeGrunddaten($listing, $fehlend);
        $this->pruefeFlaechenUndZimmer($listing, $fehlend);
        $this->pruefeVerfuegbarkeit($listing, $fehlend);
        $this->pruefePreise($listing, $fehlend, $hinweise);
        $this->pruefeEnergieausweis($listing, $fehlend);
        $this->pruefeMedien($listing, $fehlend);

        if (empty($listing->beschreibung_objekt)) {
            $fehlend['beschreibung_objekt'] = 'Objektbeschreibung';
        }

        if ($listing->ansprechpartner_user_id === null) {
            $fehlend['ansprechpartner_user_id'] = 'Ansprechpartner';
        }

        return new CompletenessResult($fehlend, $hinweise);
    }

    /**
     * @param  array<string, string>  $fehlend
     */
    private function pruefeGrunddaten(Listing $listing, array &$fehlend): void
    {
        if (empty($listing->titel)) {
            $fehlend['titel'] = 'Titel';
        }

        if (empty($listing->strasse)) {
            $fehlend['strasse'] = 'Straße';
        }

        if (empty($listing->hausnummer)) {
            $fehlend['hausnummer'] = 'Hausnummer';
        }

        if (empty($listing->plz)) {
            $fehlend['plz'] = 'Postleitzahl';
        }

        if (empty($listing->ort)) {
            $fehlend['ort'] = 'Ort';
        }
    }

    /**
     * @param  array<string, string>  $fehlend
     */
    private function pruefeFlaechenUndZimmer(Listing $listing, array &$fehlend): void
    {
        $objektart = $listing->objektart;

        if (in_array($objektart, [Objektart::Wohnung, Objektart::Haus], true)) {
            if ($listing->wohnflaeche_qm === null) {
                $fehlend['wohnflaeche_qm'] = 'Wohnfläche';
            }

            if ($listing->zimmer === null) {
                $fehlend['zimmer'] = 'Zimmer';
            }
        }

        if ($objektart === Objektart::Gewerbe && $listing->nutzflaeche_qm === null) {
            $fehlend['nutzflaeche_qm'] = 'Nutzfläche';
        }

        if (in_array($objektart, [Objektart::Haus, Objektart::Grundstueck], true) && $listing->grundstuecksflaeche_qm === null) {
            $fehlend['grundstuecksflaeche_qm'] = 'Grundstücksfläche';
        }
    }

    /**
     * @param  array<string, string>  $fehlend
     */
    private function pruefeVerfuegbarkeit(Listing $listing, array &$fehlend): void
    {
        if ($listing->vermarktungsart === Vermarktungsart::Miete && $listing->heizkosten_versorgung === null) {
            $fehlend['heizkosten_versorgung'] = 'Heizkostenversorgung';
        }

        if ($listing->verfuegbar_ab_typ === null) {
            $fehlend['verfuegbar_ab_typ'] = 'Verfügbarkeit';

            return;
        }

        if ($listing->verfuegbar_ab_typ === VerfuegbarAbTyp::Datum && $listing->verfuegbar_ab_datum === null) {
            $fehlend['verfuegbar_ab_datum'] = 'Verfügbar ab (Datum)';
        }
    }

    /**
     * @param  array<string, string>  $fehlend
     * @param  array<int, string>  $hinweise
     */
    private function pruefePreise(Listing $listing, array &$fehlend, array &$hinweise): void
    {
        $preis = $listing->price;

        if ($listing->vermarktungsart === Vermarktungsart::Miete) {
            if ($preis === null || $preis->kaltmiete_cent === null) {
                $fehlend['preis.kaltmiete_cent'] = 'Kaltmiete';
            }

            if ($preis === null || $preis->nebenkosten_cent === null) {
                $fehlend['preis.nebenkosten_cent'] = 'Nebenkosten';
            }

            if ($preis !== null
                && $preis->kaltmiete_cent !== null
                && $preis->nebenkosten_cent !== null
                && $listing->heizkosten_versorgung !== null
            ) {
                try {
                    $ergebnis = $this->rentCalculator->calculate(
                        kaltmieteCent: $preis->kaltmiete_cent,
                        nebenkostenCent: $preis->nebenkosten_cent,
                        heizkostenCent: $preis->heizkosten_cent,
                        heizkostenInNebenkostenEnthalten: $preis->heizkosten_in_nebenkosten_enthalten,
                        versorgung: $listing->heizkosten_versorgung,
                    );

                    foreach ($ergebnis->hinweise as $hinweis) {
                        $hinweise[] = $hinweis->label();
                    }
                } catch (InvalidRentInputException) {
                    // Prüfbericht 2026-09-11, Befund 2: Schritt 2 kann die
                    // Heizkostenversorgung unabhängig von Schritt 4 ändern und
                    // dabei widersprüchliche Preisangaben hinterlassen. Die
                    // Vollständigkeitsprüfung darf dadurch nie eine Ausnahme
                    // werfen (sonst 500 auf Detailseite, allen Schritten und
                    // der Veröffentlichung), sondern meldet das als fehlendes
                    // Feld; CompletenessFieldMap verweist dafür auf Schritt 4.
                    $fehlend['preis.widerspruch'] = 'Preisangaben widersprüchlich';
                }
            }
        }

        if ($listing->vermarktungsart === Vermarktungsart::Kauf) {
            if ($preis === null || $preis->kaufpreis_cent === null) {
                $fehlend['preis.kaufpreis_cent'] = 'Kaufpreis';
            }
        }

        if ($preis !== null && $preis->provision_typ === ProvisionTyp::Provisionspflichtig && empty($preis->provision_text)) {
            $fehlend['preis.provision_text'] = 'Provisionstext';
        }
    }

    /**
     * @param  array<string, string>  $fehlend
     */
    private function pruefeEnergieausweis(Listing $listing, array &$fehlend): void
    {
        if ($listing->energy === null || $listing->energy->status === null) {
            $fehlend['energie.status'] = 'Energieausweisstatus';
        }
    }

    /**
     * @param  array<string, string>  $fehlend
     */
    private function pruefeMedien(Listing $listing, array &$fehlend): void
    {
        $hatBild = $listing->media
            ->where('typ', MediaTyp::Bild)
            ->where('im_inserat', true)
            ->isNotEmpty();

        if (! $hatBild) {
            $fehlend['medien.bild'] = 'Mindestens ein Bild für das Inserat';
        }
    }
}
