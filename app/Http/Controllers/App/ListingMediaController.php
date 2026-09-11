<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Listing\ListingChangeTracker;
use App\Enums\MediaTyp;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listing\MediaStoreRequest;
use App\Http\Requests\Listing\MediaUpdateRequest;
use App\Models\Listing;
use App\Models\ListingMedia;
use App\Services\Media\MediaUploadException;
use App\Services\Media\MediaUploadService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Bild-, Grundriss- und Dokumentenverwaltung in Schritt 5 (Datenvertrag
 * Abschnitt 2.6, ADR-012).
 */
class ListingMediaController extends Controller
{
    use AuthorizesRequests;

    public function store(MediaStoreRequest $request, Listing $listing, MediaUploadService $service): RedirectResponse
    {
        $typ = MediaTyp::from($request->string('typ')->value());

        $fehler = [];
        $erfolge = 0;

        foreach ((array) $request->file('dateien', []) as $datei) {
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

    public function sort(Request $request, Listing $listing): RedirectResponse
    {
        $this->authorize('update', $listing);

        // Assoziatives Feld [Medien-ID => Position], von flow.js (data-sortable)
        // beim Verschieben aktualisiert.
        $reihenfolge = (array) $request->input('reihenfolge', []);

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
        ]);

        app(ListingChangeTracker::class)->recordChange($listing);

        return back()->with('status', 'Das Medium wurde aktualisiert.');
    }
}
