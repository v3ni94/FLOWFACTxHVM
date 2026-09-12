<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Listing\ListingChangeTracker;
use App\Enums\MediaTyp;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listing\MediaRotateRequest;
use App\Http\Requests\Listing\MediaSortRequest;
use App\Http\Requests\Listing\MediaStoreRequest;
use App\Http\Requests\Listing\MediaUpdateRequest;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Services\Media\MediaUploadException;
use App\Services\Media\MediaUploadService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;

/**
 * Bild-, Grundriss- und Dokumentenverwaltung in Schritt 5 (Datenvertrag
 * Abschnitt 2.6, ADR-012).
 */
class ListingMediaController extends Controller
{
    use AuthorizesRequests;

    /**
     * HEIC/HEIF-Dateien (Endung, weil viele iPhone-Kameras die Datei ohne
     * verlässlichen MIME-Typ ausliefern) erhalten einen eigenen Hinweis statt
     * der allgemeinen Fehlermeldung "nicht unterstützter Dateityp"
     * (Masterprompt-Abgleich B.1 Schritt 6).
     *
     * @var list<string>
     */
    private const array HEIC_ENDUNGEN = ['heic', 'heif'];

    public function store(MediaStoreRequest $request, Listing $listing, MediaUploadService $service): RedirectResponse
    {
        $typ = MediaTyp::from($request->string('typ')->value());

        $fehler = [];
        $erfolge = 0;

        foreach ((array) $request->file('dateien', []) as $datei) {
            if (! $datei instanceof UploadedFile) {
                continue;
            }

            if ($this->istHeic($datei)) {
                $fehler[] = 'HEIC wird derzeit nicht unterstützt, bitte als JPEG exportieren.';

                continue;
            }

            try {
                $service->store($listing, $datei, $typ);
                $erfolge++;
            } catch (MediaUploadException $exception) {
                $fehler[] = $exception->getMessage();
            }
        }

        if ($erfolge > 0) {
            app(ListingChangeTracker::class)->recordChange($listing);
        }

        if ($fehler !== []) {
            return back()->withErrors(['dateien' => $fehler]);
        }

        return back()->with('status', $erfolge.' Datei(en) wurden hochgeladen.');
    }

    public function destroy(Listing $listing, ListingMedia $media, MediaUploadService $service): RedirectResponse
    {
        $this->authorize('update', $listing);
        abort_unless($media->listing_id === $listing->id, 404);

        $service->delete($media);

        app(ListingChangeTracker::class)->recordChange($listing);

        return back()->with('status', 'Die Datei wurde gelöscht.');
    }

    public function sort(MediaSortRequest $request, Listing $listing): RedirectResponse
    {
        // Assoziatives Feld [Medien-ID => Position], von flow.js (data-sortable)
        // beim Verschieben aktualisiert. Die Werte sind über MediaSortRequest
        // auf 0 bis 32767 begrenzt (Prüfbericht 2026-09-11, Befund 17).
        $reihenfolge = (array) $request->validated('reihenfolge', []);

        foreach ($reihenfolge as $id => $position) {
            ListingMedia::query()
                ->where('id', (int) $id)
                ->where('listing_id', $listing->id)
                ->update(['sortierung' => (int) $position]);
        }

        app(ListingChangeTracker::class)->recordChange($listing);

        return back()->with('status', 'Die Reihenfolge wurde gespeichert.');
    }

    public function update(MediaUpdateRequest $request, Listing $listing, ListingMedia $media): RedirectResponse
    {
        abort_unless($media->listing_id === $listing->id, 404);

        $media->update([
            'titel' => $request->filled('titel') ? $request->string('titel')->value() : null,
            'im_inserat' => $request->boolean('im_inserat'),
            'freigegeben' => $request->boolean('freigegeben'),
        ]);

        app(ListingChangeTracker::class)->recordChange($listing);

        return back()->with('status', 'Das Medium wurde aktualisiert.');
    }

    /**
     * Dreht die Vorschau um 90 Grad (Masterprompt-Abgleich B.1 Schritt 6).
     * Standardrichtung ist im Uhrzeigersinn (+90); "links" dreht entgegen.
     */
    public function rotate(MediaRotateRequest $request, Listing $listing, ListingMedia $media, MediaUploadService $service): RedirectResponse
    {
        abort_unless($media->listing_id === $listing->id, 404);

        $vorzeichen = $request->string('richtung')->value() === 'links' ? -90 : 90;
        $service->rotate($media, $vorzeichen);

        app(ListingChangeTracker::class)->recordChange($listing);

        return back()->with('status', 'Das Medium wurde gedreht.');
    }

    private function istHeic(UploadedFile $datei): bool
    {
        $endung = strtolower($datei->getClientOriginalExtension());

        if (in_array($endung, self::HEIC_ENDUNGEN, true)) {
            return true;
        }

        $mime = strtolower((string) $datei->getMimeType());

        return str_contains($mime, 'heic') || str_contains($mime, 'heif');
    }
}
