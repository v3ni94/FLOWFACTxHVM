<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Enums\PortalStatus;
use App\Models\ListingPortalPublication;
use App\Models\ListingPortalStatusLog;
use App\Models\ListingRelease;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Einzige Stelle, die den Status einer Portalveröffentlichung ändert
 * (Masterprompt Abschnitt 20 und 21, Masterprompt-Abgleich B.6).
 *
 * Jeder Wechsel schreibt einen unveränderlichen Nachweis nach
 * listing_portal_status_logs: von welchem Status, nach welchem Status, aus
 * welcher Quelle (z. B. "POST /publish Antwort", "GET /estates/{id}/portals
 * onlineSince", "manuell"), zu welchem Zeitpunkt, mit welcher Freigabeversion
 * und durch welchen Benutzer. Ein Aufruf ohne Statusänderung aktualisiert nur
 * letzte_pruefung_at und schreibt keinen Nachweis.
 *
 * Weitere Attribute der Publikation (angefordert_at, bestaetigt_at,
 * zurueckgezogen_at, letzter_fehler, release_id) setzt der Aufrufer vor dem
 * Wechsel; apply() speichert die Publikation.
 */
final class PortalStatusTransition
{
    public const string QUELLE_PUBLISH_ANGEFORDERT = 'POST /publish angefordert';

    public const string QUELLE_PUBLISH_ANTWORT = 'POST /publish Antwort';

    public const string QUELLE_PUBLISH_NICHT_AUTORISIERT = 'POST /publish nicht autorisiert (HTTP 401/403)';

    public const string QUELLE_PUBLISH_OHNE_PORTALRECHT = 'POST /publish Antwort portalsWithoutAccessRights';

    public const string QUELLE_PUBLISH_FEHLER = 'POST /publish Fehler';

    public const string QUELLE_OFFLINE_ANGEFORDERT = 'POST /publish OFFLINE angefordert';

    public const string QUELLE_RUECKLESEN_ONLINE = 'GET /estates/{id}/portals onlineSince';

    public const string QUELLE_RUECKLESEN_OHNE_EINTRAG = 'GET /estates/{id}/portals ohne Eintrag';

    public const string QUELLE_UEBERFAELLIG = 'Zeitablauf ohne Rücklesen';

    public const string QUELLE_MANUELL = 'manuell';

    public static function apply(
        ListingPortalPublication $publication,
        PortalStatus $neu,
        string $nachweisQuelle,
        ?ListingRelease $release = null,
        ?User $user = null,
    ): void {
        $jetzt = Carbon::now();
        $alt = $publication->exists ? $publication->getOriginal('status') : null;

        if (is_string($alt)) {
            $alt = PortalStatus::tryFrom($alt);
        }

        if (! $alt instanceof PortalStatus) {
            $alt = null;
        }

        $publication->status = $neu;
        $publication->letzte_pruefung_at = $jetzt;

        if ($release !== null) {
            $publication->release_id = $release->getKey();
        }

        $publication->save();

        if ($alt === $neu) {
            return;
        }

        ListingPortalStatusLog::query()->create([
            'listing_id' => $publication->listing_id,
            'publication_id' => $publication->getKey(),
            'portal_id' => $publication->portal_id,
            'von_status' => $alt,
            'nach_status' => $neu,
            'nachweis_quelle' => mb_substr($nachweisQuelle, 0, 255),
            'nachweis_at' => $jetzt,
            'release_id' => $release?->getKey() ?? $publication->release_id,
            'user_id' => $user?->getKey(),
            'created_at' => $jetzt,
        ]);
    }
}
