<?php

declare(strict_types=1);

namespace App\Domain\Listing;

/**
 * Einzige Positivliste der Inseratsfelder (Datenvertrag Abschnitt 1.1, ADR-003).
 *
 * Wird von ListingContentHasher und später vom FLOWFACT-Mapper verwendet.
 * Felder aus listing_internals erscheinen hier nie. Ein Test stellt das
 * sicher.
 */
final class PublishableFields
{
    /**
     * Inseratsfelder der Tabelle listings, ohne id, Zeitstempel, status,
     * erstellt_von_user_id und inhalt_geaendert_at.
     *
     * @var list<string>
     */
    public const array LISTING = [
        'uuid',
        'objektnummer',
        'vermarktungsart',
        'objektart',
        'titel',
        'strasse',
        'hausnummer',
        'plz',
        'ort',
        'land',
        'adresse_im_inserat_anzeigen',
        'wohnflaeche_qm',
        'nutzflaeche_qm',
        'grundstuecksflaeche_qm',
        'zimmer',
        'schlafzimmer',
        'badezimmer',
        'etage',
        'etagen_gesamt',
        'baujahr',
        'zustand',
        'ausstattungsqualitaet',
        'heizungsart',
        'energietraeger',
        'heizkosten_versorgung',
        'verfuegbar_ab_typ',
        'verfuegbar_ab_datum',
        'ausstattung',
        'stellplatz_typ',
        'stellplatz_anzahl',
        'beschreibung_objekt',
        'beschreibung_ausstattung',
        'beschreibung_lage',
        'beschreibung_sonstiges',
        'ansprechpartner_user_id',
    ];

    /**
     * Preisfelder der Tabelle listing_prices, ohne id, listing_id und
     * Zeitstempel.
     *
     * @var list<string>
     */
    public const array PRICE = [
        'kaltmiete_cent',
        'nebenkosten_cent',
        'heizkosten_cent',
        'heizkosten_in_nebenkosten_enthalten',
        'warmmiete_cent',
        'kaution_cent',
        'stellplatz_miete_cent',
        'kaufpreis_cent',
        'hausgeld_cent',
        'stellplatz_kaufpreis_cent',
        'mieteinnahmen_ist_cent',
        'provision_typ',
        'provision_text',
    ];

    /**
     * Energieausweisfelder der Tabelle listing_energies, ohne id, listing_id
     * und Zeitstempel.
     *
     * @var list<string>
     */
    public const array ENERGY = [
        'status',
        'ausweistyp',
        'kennwert_kwh',
        'effizienzklasse',
        'baujahr_anlage',
        'gueltig_bis',
        'enthaelt_warmwasser',
    ];
}
