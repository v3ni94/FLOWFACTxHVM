<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

/**
 * Ordnet die Feldschlüssel aus CompletenessResult::$fehlend dem
 * Erfassungsschritt zu, in dem sie ausgefüllt werden (Datenvertrag
 * Abschnitt 5). Rein für die Oberfläche, ohne fachliche Bedeutung.
 */
final class CompletenessFieldMap
{
    /**
     * @var array<string, int>
     */
    private const array SCHRITTE = [
        'titel' => 1,
        'strasse' => 1,
        'hausnummer' => 1,
        'plz' => 1,
        'ort' => 1,
        'ansprechpartner_user_id' => 1,
        'wohnflaeche_qm' => 2,
        'nutzflaeche_qm' => 2,
        'grundstuecksflaeche_qm' => 2,
        'zimmer' => 2,
        'heizkosten_versorgung' => 2,
        'verfuegbar_ab_typ' => 2,
        'verfuegbar_ab_datum' => 2,
        'energie.status' => 3,
        'preis.kaltmiete_cent' => 4,
        'preis.nebenkosten_cent' => 4,
        'preis.kaufpreis_cent' => 4,
        'preis.provision_text' => 4,
        'medien.bild' => 5,
        'beschreibung_objekt' => 6,
    ];

    public static function schritt(string $feld): int
    {
        return self::SCHRITTE[$feld] ?? 8;
    }
}
