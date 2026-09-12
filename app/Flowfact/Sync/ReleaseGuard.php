<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Domain\Listing\ReleaseService;
use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Enums\TransferRichtung;
use App\Models\Listing;
use App\Models\ListingPortalPublication;
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
     * Ob nach dem Freigabezeitpunkt eine Deaktivierung angefordert wurde:
     * Rückzug (zurueckgezogen_at) oder Status deaktivierung_angefordert
     * beziehungsweise deaktivierung_bestaetigt, jeweils jünger als die
     * Freigabe. Ein alter Job darf dann keine Veröffentlichung mehr auslösen.
     */
    public function deaktivierungNachFreigabe(Listing $listing, ListingRelease $release): bool
    {
        $freigegebenAt = $release->freigegeben_at;

        if ($freigegebenAt === null) {
            return false;
        }

        return ListingPortalPublication::query()
            ->where('listing_id', $listing->getKey())
            ->where(function ($query) use ($freigegebenAt): void {
                $query->where('zurueckgezogen_at', '>', $freigegebenAt)
                    ->orWhere(function ($status) use ($freigegebenAt): void {
                        $status->whereIn('status', [PortalStatus::DeaktivierungAngefordert->value, PortalStatus::DeaktivierungBestaetigt->value])
                            ->where('updated_at', '>', $freigegebenAt);
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
