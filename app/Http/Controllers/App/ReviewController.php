<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Models\Listing;
use Illuminate\Http\RedirectResponse;

/**
 * Übergangsstand: "Prüfen und veröffentlichen" wird in Welle 2 neu gebaut.
 * Bis dahin erbt dieser Controller die bestehenden Aktionen (transfer,
 * publish, withdraw, markiereBereit) und leitet die Prüfansicht um.
 */
class ReviewController extends ListingController
{
    public function review(Listing $listing): RedirectResponse
    {
        $this->authorize('view', $listing);

        return redirect()->route('app.listings.step', [$listing, 8]);
    }
}
