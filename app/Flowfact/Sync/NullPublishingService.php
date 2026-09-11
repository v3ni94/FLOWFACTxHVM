<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Models\Listing;
use App\Models\User;

/**
 * Aktiv, solange kein FLOWFACT-Token hinterlegt ist. Liefert klare Meldungen
 * statt Fehler, veröffentlicht nichts.
 */
final class NullPublishingService implements PublishingService
{
    public const string MELDUNG = 'FLOWFACT ist nicht konfiguriert. Bitte hinterlegen Sie den API-Token im Adminbereich.';

    public function isConfigured(): bool
    {
        return false;
    }

    public function portals(): array
    {
        return [];
    }

    public function transfer(Listing $listing, ?User $user = null): SyncResult
    {
        return SyncResult::failed(self::MELDUNG);
    }

    public function publish(Listing $listing, array $portalIds, ?User $user = null): PublishResult
    {
        return new PublishResult(false, self::MELDUNG);
    }

    public function withdraw(Listing $listing, array $portalIds, ?User $user = null): PublishResult
    {
        return new PublishResult(false, self::MELDUNG);
    }

    public function refreshStatus(Listing $listing): void {}
}
