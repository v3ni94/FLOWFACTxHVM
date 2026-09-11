<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Models\Listing;
use App\Models\User;

/**
 * Schnittstelle zwischen Oberfläche und FLOWFACT-Connector (docs/connector.md, Abschnitt 1).
 *
 * Die Oberfläche kennt nur dieses Interface. Der Container bindet die echte
 * Umsetzung, sobald ein API-Token hinterlegt ist, sonst NullPublishingService.
 */
interface PublishingService
{
    public function isConfigured(): bool;

    /**
     * Portale des Kontos, die für eine Veröffentlichung in Frage kommen.
     *
     * @return list<PortalInfo>
     */
    public function portals(): array;

    /**
     * Legt die Entität in FLOWFACT an oder aktualisiert sie und gleicht die Medien ab.
     * Veröffentlicht nichts.
     */
    public function transfer(Listing $listing, ?User $user = null): SyncResult;

    /**
     * Fordert die Veröffentlichung auf den genannten Portalen an. Setzt nie selbst
     * den Portalstatus "aktiv"; das geschieht erst nach Rücklesen.
     *
     * @param  list<string>  $portalIds
     */
    public function publish(Listing $listing, array $portalIds, ?User $user = null): PublishResult;

    /**
     * @param  list<string>  $portalIds
     */
    public function withdraw(Listing $listing, array $portalIds, ?User $user = null): PublishResult;

    /**
     * Liest den Portalstatus aus FLOWFACT nach und aktualisiert listing_portal_publications.
     */
    public function refreshStatus(Listing $listing): void;
}
