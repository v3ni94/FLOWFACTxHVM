<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Models\ListingFlowfactLink;
use Illuminate\Support\Carbon;

/**
 * Lease auf listing_flowfact_links.sperre_bis (docs/connector.md Abschnitt 3,
 * Schritt 1; ADR-005).
 *
 * WARUM: Zwei gleichzeitige Läufe für dasselbe Objekt (Oberfläche plus Job,
 * zwei Worker) könnten beide "keine Entität gefunden" sehen und beide anlegen.
 * Die Lease wird als einzelnes bedingtes UPDATE gesetzt, damit genau ein
 * Lauf gewinnt. Abgelaufene Leases (Absturz eines Laufs) werden übernommen.
 */
final class SyncLease
{
    public function __construct(
        private readonly int $minuten = 3,
    ) {}

    public function acquire(ListingFlowfactLink $link): bool
    {
        $jetzt = Carbon::now();
        $bis = $jetzt->copy()->addMinutes($this->minuten);

        $geaendert = ListingFlowfactLink::query()
            ->whereKey($link->getKey())
            ->where(function ($query) use ($jetzt): void {
                $query->whereNull('sperre_bis')->orWhere('sperre_bis', '<', $jetzt);
            })
            ->update(['sperre_bis' => $bis]);

        if ($geaendert !== 1) {
            return false;
        }

        $link->sperre_bis = $bis;
        $link->syncOriginalAttribute('sperre_bis');

        return true;
    }

    public function release(ListingFlowfactLink $link): void
    {
        ListingFlowfactLink::query()->whereKey($link->getKey())->update(['sperre_bis' => null]);

        $link->sperre_bis = null;
        $link->syncOriginalAttribute('sperre_bis');
    }

    public function isActive(ListingFlowfactLink $link): bool
    {
        $link->refresh();

        return $link->sperre_bis !== null && $link->sperre_bis->isFuture();
    }
}
