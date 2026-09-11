<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Enums\ListingStatus;
use App\Enums\SyncStatus;
use App\Models\Listing;

/**
 * Zeichnet inhaltliche Änderungen an einem Objekt auf und zieht die daraus
 * folgenden Konsequenzen für die beiden Statusachsen (Datenvertrag Abschnitt
 * 2.2, 4.1, 4.2).
 *
 * Wird nach jedem Speichern eines Wizardschritts aufgerufen: markiert den
 * Änderungszeitpunkt, prüft, ob ein als "bereit" markiertes Objekt durch die
 * Änderung wieder unvollständig geworden ist, und markiert eine bereits
 * übertragene FLOWFACT-Verknüpfung als "geändert seit Übertragung". Die
 * Warmmiete wird hier nicht berechnet, das übernimmt Schritt 4 selbst mit dem
 * RentCalculator.
 */
final class ListingChangeTracker
{
    public function __construct(
        private readonly CompletenessCheck $completenessCheck = new CompletenessCheck,
        private readonly ListingStatusMachine $statusMachine = new ListingStatusMachine,
        private readonly ListingContentHasher $contentHasher = new ListingContentHasher,
    ) {}

    /**
     * @param  string|null  $vorherHash  Inhalts-Hash (ListingContentHasher) vor der Änderung.
     *                                   Ohne Angabe wird wie bisher immer eine inhaltliche
     *                                   Änderung angenommen. Mit Angabe wird der Hash nach dem
     *                                   Speichern verglichen; sind beide gleich, hat sich kein
     *                                   Inseratsfeld geändert (Prüfbericht 2026-09-11, Befund 12:
     *                                   z. B. Schritt 7 mit rein internen Daten darf den
     *                                   Übertragungsstatus nie kippen).
     */
    public function recordChange(Listing $listing, ?string $vorherHash = null): void
    {
        $listing->load(['price', 'energy', 'media', 'flowfactLink']);

        $inhaltGeaendert = $vorherHash === null || $vorherHash !== $this->contentHasher->hash($listing);

        if ($inhaltGeaendert) {
            $listing->markContentChanged();
            $listing->save();
        }

        if ($listing->status === ListingStatus::Bereit) {
            $ergebnis = $this->completenessCheck->check($listing);

            if (! $ergebnis->istVollstaendig()) {
                $this->statusMachine->transition($listing, ListingStatus::Entwurf);
            }
        }

        if (! $inhaltGeaendert) {
            return;
        }

        $link = $listing->flowfactLink;

        if ($link !== null && $link->sync_status === SyncStatus::Uebertragen) {
            $link->sync_status = SyncStatus::GeaendertSeitUebertragung;
            $link->save();
        }
    }
}
