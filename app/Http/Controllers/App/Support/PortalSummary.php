<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Support;

use App\Enums\PortalStatus;
use App\Models\Listing;
use App\Models\ListingPortalPublication;
use Illuminate\Support\Collection;

/**
 * Fasst die Portalveröffentlichungen eines Objekts zu einem kurzen Text für
 * die Objektübersicht zusammen, z. B. "2 aktiv, 1 ausstehend" (Datenvertrag
 * Abschnitt 2.9, 4.3).
 */
final class PortalSummary
{
    /**
     * @var array<string, string>
     */
    private const array KURZLABEL = [
        'aktiv' => 'aktiv',
        'angefordert' => 'ausstehend',
        'fehler' => 'fehler',
        'zurueckgezogen' => 'zurückgezogen',
        'unbekannt' => 'unklar',
        'nicht_veroeffentlicht' => 'nicht veröffentlicht',
    ];

    public static function text(Listing $listing): string
    {
        /** @var Collection<int, ListingPortalPublication> $veroeffentlichungen */
        $veroeffentlichungen = $listing->portalPublications;

        if ($veroeffentlichungen->isEmpty()) {
            return 'Keine Portale';
        }

        return $veroeffentlichungen
            ->groupBy(fn ($p) => $p->status->value)
            ->map(fn (Collection $gruppe, string $status): string => $gruppe->count().' '.(self::KURZLABEL[$status] ?? $status))
            ->values()
            ->implode(', ');
    }

    public static function badgeClass(Listing $listing): string
    {
        $status = $listing->portalPublications->map(fn ($p) => $p->status);

        if ($status->contains(PortalStatus::Fehler)) {
            return 'badge-error';
        }

        if ($status->contains(PortalStatus::Angefordert) || $status->contains(PortalStatus::Unbekannt)) {
            return 'badge-warning';
        }

        if ($status->contains(PortalStatus::Aktiv)) {
            return 'badge-success';
        }

        return 'badge-neutral';
    }
}
