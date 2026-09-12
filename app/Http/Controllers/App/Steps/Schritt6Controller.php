<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Steps;

use App\Enums\MediaTyp;
use App\Http\Controllers\App\Support\ListingPreviewBuilder;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Schritt 6: Bilder und Unterlagen (Masterprompt-Abgleich B.1 Schritt 6).
 *
 * Die eigentlichen Aktionen (Hochladen, Löschen, Sortieren, Drehen,
 * Beschriften, Freigabe) laufen über eigene Routen
 * (App\Http\Controllers\App\ListingMediaController), nicht über diesen
 * Schritt-Controller. show() gruppiert die Medien nur für die Anzeige;
 * store() und autosave() dienen ausschließlich der Assistenten-Navigation.
 */
final class Schritt6Controller extends AbstractStep implements StepHandler
{
    protected function schritt(): int
    {
        return 6;
    }

    public function show(Request $request, Listing $listing): View
    {
        $this->authorizeAndLoad($request, $listing);
        $listing->loadMissing('media');

        $medienNachTyp = $listing->media->groupBy(fn ($medium) => $medium->typ->value);

        // Prüfbericht 2026-09-12, Befund 4: Bildtitel mit Straße oder
        // Hausnummer trotz eingeschränkter Adressfreigabe bereits hier
        // sichtbar machen, nicht erst auf der Prüfseite.
        $adressLeckMedien = $listing->media
            ->filter(fn ($medium) => $medium->istVeroeffentlichbar() && ListingPreviewBuilder::enthaeltAdresse($listing, $medium->titel))
            ->pluck('titel')
            ->values()
            ->all();

        return view('app.listings.schritte.schritt-6', array_merge($this->headerDaten($listing), [
            'kategorien' => MediaTyp::options(),
            'medienNachTyp' => $medienNachTyp,
            'adressLeckMedien' => $adressLeckMedien,
        ]));
    }

    public function store(Request $request, Listing $listing): RedirectResponse
    {
        return $this->redirectNachSpeichern($request, $listing);
    }

    public function autosave(Request $request, Listing $listing): JsonResponse
    {
        return $this->autosaveErfolg($listing);
    }
}
