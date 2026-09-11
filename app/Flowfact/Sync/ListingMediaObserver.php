<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Models\ListingMedia;

/**
 * Merkt beim lokalen Löschen eines Mediums dessen FLOWFACT-Item zur
 * Löschung vor. Der eigentliche DELETE /items/{id} läuft im nächsten
 * Sync, damit das Löschen in der Oberfläche nicht von der API abhängt.
 */
final class ListingMediaObserver
{
    public function deleting(ListingMedia $medium): void
    {
        $flowfactId = $medium->getAttribute('flowfact_multimedia_id');

        if ($flowfactId === null || (string) $flowfactId === '') {
            return;
        }

        ListingMediaDeletion::query()->create([
            'listing_id' => $medium->listing_id,
            'flowfact_multimedia_id' => (string) $flowfactId,
            'created_at' => now(),
        ]);
    }
}
