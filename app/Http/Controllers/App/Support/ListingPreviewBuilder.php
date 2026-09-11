<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

use App\Domain\Settings\SettingsRepository;
use App\Models\Listing;

/**
 * Baut die Datengrundlage für die Inseratsvorschau in Schritt 8 (Datenvertrag
 * Abschnitt 5.8) exakt aus den Inseratsfeldern. Liest ausschließlich
 * Listing-Attribute, die Relationen price/energy/media sowie firma.*
 * Einstellungen, nie listing_internals (ADR-003).
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
        $adresse = $listing->adresse_im_inserat_anzeigen
            ? $listing->adresseKurz()
            : trim(($listing->plz ?? '').' '.($listing->ort ?? ''));

        $medien = $listing->media
            ->where('im_inserat', true)
            ->sortBy('sortierung')
            ->values();

        return [
            'titel' => $listing->titel,
            'adresse' => $adresse,
            'vermarktungsart' => $listing->vermarktungsart,
            'objektart' => $listing->objektart,
            'wohnflaeche_qm' => $listing->wohnflaeche_qm,
            'nutzflaeche_qm' => $listing->nutzflaeche_qm,
            'grundstuecksflaeche_qm' => $listing->grundstuecksflaeche_qm,
            'zimmer' => $listing->zimmer,
            'preis' => $listing->price,
            'energie' => $listing->energy,
            'beschreibung_objekt' => $listing->beschreibung_objekt,
            'beschreibung_ausstattung' => $listing->beschreibung_ausstattung,
            'beschreibung_lage' => $listing->beschreibung_lage,
            'beschreibung_sonstiges' => $listing->beschreibung_sonstiges,
            'medien' => $medien,
            'ansprechpartner' => $listing->ansprechpartner,
            'firma' => [
                'name' => $this->settings->get('firma.name'),
                'strasse' => $this->settings->get('firma.strasse'),
                'plz_ort' => $this->settings->get('firma.plz_ort'),
                'telefon' => $this->settings->get('firma.telefon'),
                'email' => $this->settings->get('firma.email'),
            ],
        ];
    }
}
