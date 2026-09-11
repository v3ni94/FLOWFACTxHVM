<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Models\ListingFlowfactLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Lease auf listing_flowfact_links.sperre_bis und sperre_token
 * (docs/connector.md Abschnitt 3, Schritt 1; ADR-005).
 *
 * WARUM: Zwei gleichzeitige Läufe für dasselbe Objekt (Oberfläche plus Job,
 * zwei Worker) könnten beide "keine Entität gefunden" sehen und beide anlegen.
 * Die Lease wird als einzelnes bedingtes UPDATE gesetzt, damit genau ein
 * Lauf gewinnt. Abgelaufene Leases (Absturz eines Laufs) werden übernommen.
 *
 * WARUM Token (Prüfbericht 2026-09-11, Befund 7): Ein Lauf, der länger als
 * die Lease dauert, darf beim Abschluss nicht die Lease eines Nachfolgers
 * löschen. Freigabe und Verlängerung sind deshalb bedingte UPDATEs auf das
 * eigene Token.
 */
final class SyncLease
{
    public function __construct(
        private readonly int $minuten = 3,
    ) {}

    /**
     * @return string|null Token der erworbenen Lease, null wenn eine fremde Lease aktiv ist
     */
    public function acquire(ListingFlowfactLink $link): ?string
    {
        $jetzt = Carbon::now();
        $bis = $jetzt->copy()->addMinutes($this->minuten);
        $token = Str::random(40);

        $geaendert = ListingFlowfactLink::query()
            ->whereKey($link->getKey())
            ->where(function ($query) use ($jetzt): void {
                $query->whereNull('sperre_bis')->orWhere('sperre_bis', '<', $jetzt);
            })
            ->update(['sperre_bis' => $bis, 'sperre_token' => $token]);

        if ($geaendert !== 1) {
            return null;
        }

        $link->sperre_bis = $bis;
        $link->sperre_token = $token;
        $link->syncOriginalAttributes(['sperre_bis', 'sperre_token']);

        return $token;
    }

    /**
     * Herzschlag: verlängert die eigene Lease um die volle Laufzeit.
     *
     * @return bool false, wenn die Lease inzwischen einem anderen Lauf gehört
     */
    public function extend(ListingFlowfactLink $link, string $token): bool
    {
        $bis = Carbon::now()->addMinutes($this->minuten);

        $geaendert = ListingFlowfactLink::query()
            ->whereKey($link->getKey())
            ->where('sperre_token', $token)
            ->update(['sperre_bis' => $bis]);

        if ($geaendert !== 1) {
            return false;
        }

        $link->sperre_bis = $bis;
        $link->syncOriginalAttribute('sperre_bis');

        return true;
    }

    /**
     * Gibt ausschließlich die eigene Lease frei. Eine fremde Lease (anderes
     * Token) bleibt unberührt.
     */
    public function release(ListingFlowfactLink $link, string $token): void
    {
        $geaendert = ListingFlowfactLink::query()
            ->whereKey($link->getKey())
            ->where('sperre_token', $token)
            ->update(['sperre_bis' => null, 'sperre_token' => null]);

        if ($geaendert !== 1) {
            return;
        }

        $link->sperre_bis = null;
        $link->sperre_token = null;
        $link->syncOriginalAttributes(['sperre_bis', 'sperre_token']);
    }

    public function isActive(ListingFlowfactLink $link): bool
    {
        $link->refresh();

        return $link->sperre_bis !== null && $link->sperre_bis->isFuture();
    }
}
