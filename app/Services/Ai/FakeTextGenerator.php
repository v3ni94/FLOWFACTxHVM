<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\TextFeld;
use App\Models\Listing;

/**
 * Deterministischer Platzhalter ohne externen Aufruf. Aktiv in Tests und
 * solange kein KI-Anbieter konfiguriert ist.
 */
final class FakeTextGenerator implements TextGenerator
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function modell(): string
    {
        return 'fake';
    }

    public function generate(Listing $listing, array $felder): array
    {
        $objektart = $listing->objektart?->label() ?? 'Objekt';
        $ort = $listing->ort ?? '';
        $zimmer = $listing->zimmer !== null ? rtrim(rtrim(number_format((float) $listing->zimmer, 1, ',', '.'), '0'), ',') : null;
        $flaeche = $listing->wohnflaeche_qm !== null ? number_format((float) $listing->wohnflaeche_qm, 0, ',', '.') : null;

        $vorschlaege = [];

        foreach ($felder as $feld) {
            $vorschlaege[$feld->value] = match ($feld) {
                TextFeld::Titel => trim(($zimmer ? $zimmer.'-Zimmer-' : '').$objektart.($ort !== '' ? ' in '.$ort : '')),
                TextFeld::BeschreibungObjekt => trim('Diese '.$objektart.($flaeche ? ' mit ca. '.$flaeche.' m² Wohnfläche' : '').($ort !== '' ? ' befindet sich in '.$ort : '').'. Die Angaben beruhen auf den erfassten Objektdaten und sind vor Veröffentlichung zu prüfen.'),
                TextFeld::BeschreibungAusstattung => 'Die Ausstattung ergibt sich aus den erfassten Merkmalen des Objekts.',
                TextFeld::BeschreibungLage => trim(($ort !== '' ? $ort.' bietet ' : 'Der Standort bietet ').'eine gute Anbindung an Nahversorgung und Verkehr.'),
                TextFeld::BeschreibungSonstiges => 'Alle Angaben nach bestem Wissen, Irrtum vorbehalten.',
            };
        }

        return $vorschlaege;
    }
}
