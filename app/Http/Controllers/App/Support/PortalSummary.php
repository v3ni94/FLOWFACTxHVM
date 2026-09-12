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
     * Prüfbericht 2026-09-12, Befund 7: statt einer eigenen, unvollständigen
     * Kurzlabel-Tabelle (die die drei neuen Portalstatus manuelle
     * Freigabe erforderlich, Deaktivierung angefordert und Deaktivierung
     * bestätigt nicht kannte und als rohen Enum-Wert anzeigte) wird
     * durchgängig PortalStatus::label() verwendet.
     */
    public static function text(Listing $listing): string
    {
        /** @var Collection<int, ListingPortalPublication> $veroeffentlichungen */
        $veroeffentlichungen = $listing->portalPublications;

        if ($veroeffentlichungen->isEmpty()) {
            return 'Keine Portale';
        }

        $zusammenfassung = $veroeffentlichungen
            ->groupBy(fn ($p) => $p->status->value)
            ->map(fn (Collection $gruppe, string $status): string => $gruppe->count().' '.PortalStatus::from($status)->label())
            ->values()
            ->implode(', ');

        return self::istTeilweiseVeroeffentlicht($listing) ? 'Teilweise veröffentlicht: '.$zusammenfassung : $zusammenfassung;
    }

    /**
     * Ob mindestens ein Portal aktiv ist und mindestens ein weiteres nicht
     * (Masterprompt Abschnitt 7): das Objekt ist weder vollständig noch gar
     * nicht veröffentlicht.
     */
    public static function istTeilweiseVeroeffentlicht(Listing $listing): bool
    {
        $status = $listing->portalPublications->map(fn ($p) => $p->status);

        if (! $status->contains(PortalStatus::Aktiv)) {
            return false;
        }

        return $status->contains(fn (PortalStatus $wert): bool => $wert !== PortalStatus::Aktiv
            && $wert !== PortalStatus::NichtVeroeffentlicht);
    }

    /**
     * Prüfbericht 2026-09-12, Befund 7: die Rangfolge folgt weiterhin
     * Fehler vor ausstehend/unklar vor aktiv vor neutral, aber gestützt auf
     * PortalStatus::badgeClass() statt einer eigenen, unvollständigen Liste,
     * damit auch die drei neuen Portalstatus (manuelle Freigabe
     * erforderlich, Deaktivierung angefordert, Deaktivierung bestätigt)
     * korrekt eingeordnet werden.
     */
    public static function badgeClass(Listing $listing): string
    {
        $badges = $listing->portalPublications->map(fn ($p) => $p->status->badgeClass());

        return match (true) {
            $badges->contains('badge-error') => 'badge-error',
            $badges->contains('badge-warning') => 'badge-warning',
            $badges->contains('badge-success') => 'badge-success',
            default => 'badge-neutral',
        };
    }
}
