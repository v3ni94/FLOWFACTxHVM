<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Domain\Listing\ReleaseService;
use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Enums\TransferRichtung;
use App\Models\Listing;
use App\Models\ListingPortalPublication;
use App\Models\ListingPortalStatusLog;
use App\Models\ListingRelease;
use App\Models\TransferLog;

/**
 * Schutz vor veralteten Freigaben und alten Jobs (Masterprompt Abschnitt 19,
 * 24; Masterprompt-Abgleich B.6).
 *
 * Ein Job trägt die release_id, mit der er eingereiht wurde. Vor jeder
 * Handlung prüft er: Ist die Version noch die jüngste? Ist das Objekt nicht
 * archiviert? Wurde nach der Freigabe eine Deaktivierung angefordert? Jede
 * Übersprungene Handlung hinterlässt einen Eintrag im Übertragungsprotokoll,
 * damit der Vorgang nachvollziehbar bleibt, ohne dass ein API-Aufruf erfolgt.
 */
final class ReleaseGuard
{
    public const string MELDUNG_VERALTET = 'Veraltete Freigabe übersprungen';

    public const string MELDUNG_ARCHIVIERT = 'Objekt archiviert, Übertragung übersprungen';

    public const string MELDUNG_DEAKTIVIERUNG = 'Deaktivierung nach der Freigabe angefordert, Veröffentlichung übersprungen';

    public function __construct(
        private readonly ReleaseService $releases,
    ) {}

    public function latest(Listing $listing): ?ListingRelease
    {
        return $this->releases->latest($listing);
    }

    /**
     * Ob die Version noch die jüngste Freigabe des Objekts ist.
     */
    public function istAktuell(Listing $listing, ListingRelease $release): bool
    {
        $latest = $this->latest($listing);

        return $latest !== null && (int) $latest->getKey() === (int) $release->getKey();
    }

    /**
     * Ob nach dem Freigabezeitpunkt eine Deaktivierung angefordert wurde. Ein
     * alter Job darf dann keine Veröffentlichung mehr auslösen.
     *
     * Prüfbericht 2026-09-12, Befund 2: Die Antwort stammt ausschließlich aus
     * Nachweisen, nie aus updated_at der Publikation. Ein Rücklesen ohne
     * Statuswechsel (Scheduler, manuelles Nachlesen) berührt keine Zeile
     * (beruehre() ohne Zeitstempel) und kann eine Deaktivierung von vor der
     * Freigabe daher nicht als "nach der Freigabe" erscheinen lassen.
     *
     * Quellen:
     * - zurueckgezogen_at der Publikation jünger als freigegeben_at (Zeitpunkt
     *   der Anforderung, wird bei jeder neuen Veröffentlichung geleert);
     * - listing_portal_status_logs mit nach_status deaktivierung_angefordert
     *   oder zurueckgezogen, created_at jünger als freigegeben_at;
     * - listing_portal_status_logs mit nach_status deaktivierung_bestaetigt,
     *   created_at jünger als freigegeben_at, sofern der Wechsel nicht aus
     *   deaktivierung_angefordert kam. Die Bestätigung einer Anforderung ist
     *   kein eigener Rückzug: War die Anforderung älter als die Freigabe,
     *   bleibt die jüngere Freigabe wirksam; war sie jünger, greift bereits
     *   der Nachweis der Anforderung.
     */
    public function deaktivierungNachFreigabe(Listing $listing, ListingRelease $release): bool
    {
        $freigegebenAt = $release->freigegeben_at;

        if ($freigegebenAt === null) {
            return false;
        }

        $rueckzug = ListingPortalPublication::query()
            ->where('listing_id', $listing->getKey())
            ->where('zurueckgezogen_at', '>', $freigegebenAt)
            ->exists();

        if ($rueckzug) {
            return true;
        }

        return ListingPortalStatusLog::query()
            ->where('listing_id', $listing->getKey())
            ->where('created_at', '>', $freigegebenAt)
            ->where(function ($query): void {
                $query->whereIn('nach_status', [PortalStatus::DeaktivierungAngefordert->value, PortalStatus::Zurueckgezogen->value])
                    ->orWhere(function ($bestaetigt): void {
                        $bestaetigt->where('nach_status', PortalStatus::DeaktivierungBestaetigt->value)
                            ->where(function ($von): void {
                                $von->whereNull('von_status')
                                    ->orWhere('von_status', '!=', PortalStatus::DeaktivierungAngefordert->value);
                            });
                    });
            })
            ->exists();
    }

    /**
     * Gemeinsame Vorprüfung der Jobs. Liefert null, wenn gehandelt werden
     * darf, sonst die protokollierte Meldung.
     */
    public function pruefeJob(Listing $listing, ?int $releaseId, string $aktion): ?string
    {
        if ($listing->status === ListingStatus::Archiviert) {
            return $this->protokolliere($listing, $aktion, self::MELDUNG_ARCHIVIERT, $releaseId);
        }

        if ($releaseId === null) {
            return null;
        }

        $latest = $this->latest($listing);

        if ($latest === null || (int) $latest->getKey() !== $releaseId) {
            return $this->protokolliere($listing, $aktion, self::MELDUNG_VERALTET, $releaseId, $latest?->getKey());
        }

        return null;
    }

    public function protokolliere(Listing $listing, string $aktion, string $meldung, ?int $releaseId = null, ?int $aktuelleReleaseId = null): string
    {
        TransferLog::query()->create([
            'listing_id' => $listing->getKey(),
            'user_id' => null,
            'aktion' => mb_substr($aktion, 0, 255),
            'richtung' => TransferRichtung::Ausgehend,
            'http_status' => null,
            'erfolgreich' => true,
            'zusammenfassung' => mb_substr($meldung, 0, 255),
            'details' => [
                'release_id' => $releaseId,
                'aktuelle_release_id' => $aktuelleReleaseId,
                'hinweis' => 'Kein API-Aufruf ausgeführt.',
            ],
            'dauer_ms' => 0,
        ]);

        return $meldung;
    }
}
