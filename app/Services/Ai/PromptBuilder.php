<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\TextFeld;
use App\Models\Listing;
use App\Support\Money;

/**
 * Baut den System- und Benutzerprompt für Textvorschläge ausschließlich aus
 * der Positivliste der Inseratsfelder (Datenvertrag Abschnitt 1 und 2.7,
 * ADR-003, ADR-009).
 *
 * WARUM: Diese Klasse liest niemals die Relation "internal" und keine
 * personenbezogenen Felder (Ansprechpartner, uuid, Objektnummer). Ein Test mit
 * Markerwerten in listing_internals belegt, dass diese nie im Prompt
 * erscheinen.
 */
final class PromptBuilder
{
    /**
     * @var array<string, string>
     */
    private const array AUSSTATTUNG_LABEL = [
        'balkon' => 'Balkon',
        'terrasse' => 'Terrasse',
        'garten' => 'Garten',
        'keller' => 'Keller',
        'aufzug' => 'Aufzug',
        'einbaukueche' => 'Einbauküche',
        'gaeste_wc' => 'Gäste-WC',
        'barrierefrei' => 'Barrierefrei',
        'moebliert' => 'Möbliert',
        'wg_geeignet' => 'WG-geeignet',
        'haustiere_erlaubt' => 'Haustiere erlaubt',
    ];

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
            Sie sind Texter für Immobilienanzeigen der Hausverwaltung Müller GmbH. Sie
            verfassen sachliche, portaltaugliche Inseratstexte ausschließlich aus den in
            der folgenden Benutzernachricht angegebenen Objektdaten.

            Regeln:
            - Erfinden Sie keine Tatsachen, Zahlen oder Eigenschaften, die nicht angegeben sind.
            - Verwenden Sie keine Superlative und keine Übertreibungen.
            - Verwenden Sie keine Gedankenstriche, sondern Kommas oder eine andere Formulierung.
            - Schreiben Sie sachlich, seriös und in der formellen Anrede "Sie".
            - Verwenden Sie keine Umgangssprache und keine Emojis.

            Längenvorgaben je Feld:
            - titel: höchstens 100 Zeichen
            - beschreibung_objekt: 600 bis 1200 Zeichen
            - beschreibung_ausstattung: 300 bis 700 Zeichen
            - beschreibung_lage: 300 bis 700 Zeichen
            - beschreibung_sonstiges: höchstens 300 Zeichen

            Geben Sie ausschließlich ein JSON-Objekt zurück. Die Schlüssel entsprechen
            genau den in der Benutzernachricht angeforderten Feldnamen, die Werte sind die
            erzeugten Texte als reine Zeichenketten. Kein Fließtext, keine Code-Zäune,
            keine Erklärung außerhalb des JSON-Objekts.
            PROMPT;
    }

    /**
     * @param  list<TextFeld>  $felder
     */
    public function userMessage(Listing $listing, array $felder): string
    {
        $zeilen = ['Objektdaten:'];

        foreach ($this->objektZeilen($listing) as $zeile) {
            $zeilen[] = '- '.$zeile;
        }

        $zeilen[] = '';
        $zeilen[] = 'Erzeugen Sie ein JSON-Objekt mit genau diesen Schlüsseln: '
            .implode(', ', array_map(fn (TextFeld $feld): string => $feld->value, $felder)).'.';

        return implode("\n", $zeilen);
    }

    /**
     * @return list<string>
     */
    private function objektZeilen(Listing $listing): array
    {
        $zeilen = [];

        $zeilen[] = 'Vermarktungsart: '.($listing->vermarktungsart?->label() ?? 'unbekannt');
        $zeilen[] = 'Objektart: '.($listing->objektart?->label() ?? 'unbekannt');

        if ($listing->adresse_im_inserat_anzeigen) {
            $strassenteil = trim(sprintf('%s %s', $listing->strasse ?? '', $listing->hausnummer ?? ''));
            $ortsteil = trim(sprintf('%s %s', $listing->plz ?? '', $listing->ort ?? ''));
            $zeilen[] = 'Adresse: '.implode(', ', array_filter([$strassenteil, $ortsteil], fn (string $teil): bool => $teil !== ''));
        } else {
            $zeilen[] = 'Lage: '.trim(sprintf('%s %s', $listing->plz ?? '', $listing->ort ?? ''));
        }

        $zeilen = array_merge($zeilen, array_filter([
            $this->zahl('Wohnfläche', $listing->wohnflaeche_qm, 'm²'),
            $this->zahl('Nutzfläche', $listing->nutzflaeche_qm, 'm²'),
            $this->zahl('Grundstücksfläche', $listing->grundstuecksflaeche_qm, 'm²'),
            $this->zahl('Zimmer', $listing->zimmer),
            $this->zahl('Schlafzimmer', $listing->schlafzimmer),
            $this->zahl('Badezimmer', $listing->badezimmer),
            $this->zahl('Etage', $listing->etage),
            $this->zahl('Etagen gesamt', $listing->etagen_gesamt),
            $this->zahl('Baujahr', $listing->baujahr),
            $listing->zustand !== null ? 'Zustand: '.$listing->zustand->label() : null,
            $listing->ausstattungsqualitaet !== null ? 'Ausstattungsqualität: '.$listing->ausstattungsqualitaet->label() : null,
            $listing->heizungsart !== null ? 'Heizungsart: '.$listing->heizungsart->label() : null,
            $listing->energietraeger !== null ? 'Energieträger: '.$listing->energietraeger->label() : null,
            $listing->heizkosten_versorgung !== null ? 'Heizkostenversorgung: '.$listing->heizkosten_versorgung->label() : null,
            $this->verfuegbarkeit($listing),
            $listing->stellplatz_typ !== null ? 'Stellplatz: '.$listing->stellplatz_typ->label() : null,
            $this->zahl('Anzahl Stellplätze', $listing->stellplatz_anzahl),
        ]));

        $ausstattung = $this->ausstattungsMerkmale($listing);

        if ($ausstattung !== []) {
            $zeilen[] = 'Ausstattungsmerkmale: '.implode(', ', $ausstattung);
        }

        $zeilen = array_merge($zeilen, $this->preisZeilen($listing));
        $zeilen = array_merge($zeilen, $this->energieZeilen($listing));

        return array_values($zeilen);
    }

    private function verfuegbarkeit(Listing $listing): ?string
    {
        if ($listing->verfuegbar_ab_typ === null) {
            return null;
        }

        if ($listing->verfuegbar_ab_typ->value === 'datum' && $listing->verfuegbar_ab_datum !== null) {
            return 'Verfügbar ab: '.$listing->verfuegbar_ab_datum->format('d.m.Y');
        }

        return 'Verfügbarkeit: '.$listing->verfuegbar_ab_typ->label();
    }

    /**
     * @return list<string>
     */
    private function ausstattungsMerkmale(Listing $listing): array
    {
        $merkmale = is_array($listing->ausstattung) ? $listing->ausstattung : [];
        $ergebnis = [];

        foreach (self::AUSSTATTUNG_LABEL as $schluessel => $label) {
            if ((bool) ($merkmale[$schluessel] ?? false)) {
                $ergebnis[] = $label;
            }
        }

        return $ergebnis;
    }

    /**
     * @return list<string>
     */
    private function preisZeilen(Listing $listing): array
    {
        $price = $listing->price;

        if ($price === null) {
            return [];
        }

        return array_values(array_filter([
            $price->kaltmiete_cent !== null ? 'Kaltmiete: '.Money::format($price->kaltmiete_cent) : null,
            $price->nebenkosten_cent !== null ? 'Nebenkosten: '.Money::format($price->nebenkosten_cent) : null,
            $price->warmmiete_cent !== null ? 'Warmmiete: '.Money::format($price->warmmiete_cent) : null,
            $price->kaution_cent !== null ? 'Kaution: '.Money::format($price->kaution_cent) : null,
            $price->stellplatz_miete_cent !== null ? 'Stellplatzmiete: '.Money::format($price->stellplatz_miete_cent) : null,
            $price->kaufpreis_cent !== null ? 'Kaufpreis: '.Money::format($price->kaufpreis_cent) : null,
            $price->hausgeld_cent !== null ? 'Hausgeld: '.Money::format($price->hausgeld_cent) : null,
            $price->stellplatz_kaufpreis_cent !== null ? 'Stellplatzkaufpreis: '.Money::format($price->stellplatz_kaufpreis_cent) : null,
            $price->mieteinnahmen_ist_cent !== null ? 'Aktuelle Mieteinnahmen: '.Money::format($price->mieteinnahmen_ist_cent) : null,
            $price->provision_typ !== null ? 'Provision: '.$price->provision_typ->label() : null,
        ]));
    }

    /**
     * @return list<string>
     */
    private function energieZeilen(Listing $listing): array
    {
        $energy = $listing->energy;

        if ($energy === null) {
            return [];
        }

        return array_values(array_filter([
            $energy->status !== null ? 'Energieausweis: '.$energy->status->label() : null,
            $energy->ausweistyp !== null ? 'Ausweistyp: '.$energy->ausweistyp->label() : null,
            $energy->kennwert_kwh !== null ? 'Energiekennwert: '.$energy->kennwert_kwh.' kWh/(m²·a)' : null,
            $energy->effizienzklasse !== null ? 'Effizienzklasse: '.$energy->effizienzklasse->label() : null,
            $energy->baujahr_anlage !== null ? 'Baujahr der Heizungsanlage: '.$energy->baujahr_anlage : null,
        ]));
    }

    private function zahl(string $label, mixed $wert, string $einheit = ''): ?string
    {
        if ($wert === null || $wert === '') {
            return null;
        }

        $text = is_numeric($wert) ? rtrim(rtrim(number_format((float) $wert, 2, ',', '.'), '0'), ',') : (string) $wert;

        return $label.': '.$text.($einheit !== '' ? ' '.$einheit : '');
    }
}
