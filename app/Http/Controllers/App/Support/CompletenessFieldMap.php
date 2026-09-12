<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

/**
 * Ordnet die Feldschlüssel aus CompletenessResult::$fehlend dem
 * Erfassungsschritt zu, in dem sie ausgefüllt werden (Masterprompt-Abgleich
 * B.1, B.4). Rein für die Oberfläche, ohne fachliche Bedeutung.
 *
 * App\Domain\Listing\Befund trägt seit Welle 1 den Schritt bereits selbst
 * (schon nach der neuen Zählung, siehe CompletenessCheck und
 * EnergyRequirements). Diese Zuordnung bleibt als Sicht auf
 * CompletenessResult::$fehlend erhalten, das nur Feld => Label liefert, für
 * Stellen, die keine Befund-Liste zur Verfügung haben (z. B.
 * ListingController).
 */
final class CompletenessFieldMap
{
    /**
     * @var array<string, int>
     */
    private const array SCHRITTE = [
        // Schritt 1: Vermietung oder Verkauf
        'ansprechpartner_user_id' => 1,
        'verfuegbar_ab_typ' => 1,
        'verfuegbar_ab_datum' => 1,
        'nutzungsstatus' => 1,
        // Schritt 2: Adresse und Lage
        'strasse' => 2,
        'hausnummer' => 2,
        'plz' => 2,
        'ort' => 2,
        // Schritt 3: Flächen und Objektdaten
        'wohnflaeche_qm' => 3,
        'nutzflaeche_qm' => 3,
        'gewerbeflaeche_qm' => 3,
        'grundstuecksflaeche_qm' => 3,
        'zimmer' => 3,
        'baujahr' => 3,
        // Schritt 4: Preise und Heizung
        'heizkosten_versorgung' => 4,
        'energietraeger' => 4,
        'preis.kaltmiete_cent' => 4,
        'preis.nebenkosten_cent' => 4,
        'preis.widerspruch' => 4,
        'preis.kaufpreis_cent' => 4,
        'preis.provision_text' => 4,
        'preis.provision_bestaetigt' => 4,
        'preis.stellplatz_widerspruch' => 4,
        'preis.stellplatz_miete_cent' => 4,
        'preis.stellplatz_kaufpreis_cent' => 4,
        // Schritt 5: Ausstattung und Energieausweis
        'ausstattung' => 5,
        'energie.status' => 5,
        'energie.ausnahme' => 5,
        'energie.ausweistyp' => 5,
        'energie.kennwert_kwh' => 5,
        'energie.effizienzklasse' => 5,
        // Schritt 6: Bilder und Unterlagen
        'medien.bild' => 6,
        // Schritt 7: Überschrift und interne Bezeichnung
        'titel' => 7,
        // Schritt 8: Beschreibungen
        'beschreibung_objekt' => 8,
        'beschreibung_lage' => 8,
    ];

    public static function schritt(string $feld): int
    {
        return self::SCHRITTE[$feld] ?? 8;
    }
}
