<?php

declare(strict_types=1);

namespace App\Domain\Listing;

/**
 * Einzige Positivliste der Inseratsfelder (Datenvertrag Abschnitt 1.1, ADR-003,
 * Masterprompt-Abgleich B.2).
 *
 * Wird von ListingContentHasher, ListingSnapshot und dem FLOWFACT-Mapper
 * verwendet. Felder aus listing_internals erscheinen hier nie, ebenso wenig
 * interne_bezeichnung, provision_bestaetigt (Bestätigung, kein Inhalt) und die
 * Ausnahmebestätigung des Energieausweises. Ein Test stellt das sicher.
 */
final class PublishableFields
{
    /**
     * Inseratsfelder der Tabelle listings, ohne id, Zeitstempel, status,
     * erstellt_von_user_id, bearbeiter_user_id, freigegeben_fuer_alle,
     * interne_bezeichnung und inhalt_geaendert_at.
     *
     * @var list<string>
     */
    public const array LISTING = [
        'uuid',
        'objektnummer',
        'vermarktungsart',
        'objektart',
        'gewerbe_unterart',
        'nutzungsstatus',
        'titel',
        'strasse',
        'hausnummer',
        'adresszusatz',
        'plz',
        'ort',
        'stadtteil',
        'land',
        'adresse_im_inserat_anzeigen',
        'adress_freigabe',
        'wohnflaeche_qm',
        'nutzflaeche_qm',
        'gewerbeflaeche_qm',
        'grundstuecksflaeche_qm',
        'zimmer',
        'schlafzimmer',
        'badezimmer',
        'etage',
        'etagen_gesamt',
        'baujahr',
        'modernisierungsjahr',
        'zustand',
        'ausstattungsqualitaet',
        'heizungsart',
        'energietraeger',
        'heizkosten_versorgung',
        'heizung_waermeabgabe',
        'heizung_warmwasser',
        'verfuegbar_ab_typ',
        'verfuegbar_ab_datum',
        'ausstattung',
        'einbaukueche_mitvermietet',
        'stellplatz_typ',
        'stellplatz_anzahl',
        'beschreibung_objekt',
        'beschreibung_ausstattung',
        'beschreibung_lage',
        'beschreibung_sonstiges',
        'ansprechpartner_user_id',
    ];

    /**
     * Preisfelder der Tabelle listing_prices, ohne id, listing_id,
     * provision_bestaetigt und Zeitstempel.
     *
     * @var list<string>
     */
    public const array PRICE = [
        'kaltmiete_cent',
        'nebenkosten_cent',
        'heizkosten_cent',
        'heizkosten_in_nebenkosten_enthalten',
        'heizkosten_struktur',
        'warmmiete_cent',
        'kaution_cent',
        'stellplatz_miete_cent',
        'stellplatz_modus',
        'kaufpreis_cent',
        'hausgeld_cent',
        'stellplatz_kaufpreis_cent',
        'stellplatz_im_kaufpreis',
        'mieteinnahmen_ist_cent',
        'provision_typ',
        'provision_text',
    ];

    /**
     * Energieausweisfelder der Tabelle listing_energies, ohne id, listing_id,
     * Ausnahmebegründung und Ausnahmebestätigung und Zeitstempel.
     *
     * @var list<string>
     */
    public const array ENERGY = [
        'status',
        'ausweistyp',
        'ausstellungsdatum',
        'kennwert_kwh',
        'kennwert_strom_kwh',
        'effizienzklasse',
        'baujahr_anlage',
        'gueltig_bis',
        'enthaelt_warmwasser',
    ];

    /**
     * Metadaten je freigegebenem Medium, die in Hash und Freigabeversion
     * eingehen (Masterprompt-Abgleich B.6).
     *
     * @var list<string>
     */
    public const array MEDIA = [
        'typ',
        'pruefsumme_sha256',
        'sortierung',
        'titel',
        'rotation',
        'freigegeben',
    ];
}
