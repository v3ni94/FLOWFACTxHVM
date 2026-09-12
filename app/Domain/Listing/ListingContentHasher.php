<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Models\Listing;

/**
 * Bildet einen stabilen Hash über die veröffentlichbaren Daten eines Objekts
 * (Datenvertrag Abschnitt 2.8, ADR-003, ADR-005, Masterprompt-Abgleich B.6).
 *
 * Der Hash wird über die ListingSnapshot-Daten gebildet (Positivliste aus
 * PublishableFields plus Metadaten der freigegebenen Medien einschließlich
 * Drehung und Freigabe). Er ändert sich bei jeder inhaltlichen Änderung der
 * Positivliste und bleibt bei Änderungen an listing_internals oder am
 * Bearbeitungsstatus unverändert. Damit stimmt der Hash einer
 * Freigabeversion immer mit dem eingefrorenen Inhalt überein.
 */
final class ListingContentHasher
{
    public function hash(Listing $listing): string
    {
        return $this->hashSnapshot(ListingSnapshot::fromListing($listing));
    }

    public function hashSnapshot(ListingSnapshot $snapshot): string
    {
        return hash('sha256', json_encode(
            $snapshot->hashDaten(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }
}
