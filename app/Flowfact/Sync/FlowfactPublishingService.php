<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Domain\Listing\CompletenessCheck;
use App\Domain\Listing\IllegalStatusTransitionException;
use App\Domain\Listing\ListingStatusMachine;
use App\Domain\Listing\ReleaseService;
use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Client\Exceptions\FlowfactException;
use App\Flowfact\Client\TokenProvider;
use App\Flowfact\Client\TokenScrubber;
use App\Flowfact\Services\PortalService;
use App\Models\Listing;
use App\Models\ListingPortalPublication;
use App\Models\ListingPortalStatusLog;
use App\Models\ListingRelease;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Umsetzung der Schnittstelle zur Oberfläche (docs/connector.md Abschnitt 5,
 * Datenvertrag Abschnitt 4.3, ADR-004, Masterprompt Abschnitt 19 bis 21, 24).
 *
 * WARUM Freigabeversion: publish() arbeitet mit der jüngsten ListingRelease.
 * Die Portale des Aufrufs müssen Teil der Freigabe sein, die Übertragung
 * erfolgt mit deren Payload, jede Publikation trägt die release_id.
 *
 * WARUM Rücklesen vor "aktiv": Das Absenden von POST /publish beweist nur die
 * Annahme des Auftrags, bei asynchroner Verarbeitung kommt gar kein Körper.
 * "aktiv" wird ausschließlich aus GET /estates/{id}/portals mit onlineSince
 * gesetzt. Bis dahin zeigt die Oberfläche "Bestätigung ausstehend".
 *
 * Fall B (Masterprompt Abschnitt 20): Antwortet POST /publish mit 401 oder 403,
 * obwohl die vorherigen Aufrufe (Übertragung, Portalliste) funktioniert haben,
 * oder nennt die Antwort das Portal unter portalsWithoutAccessRights, fehlt dem
 * API-Benutzer das Veröffentlichungsrecht. Das Objekt ist dann vollständig in
 * FLOWFACT vorbereitet, die Portalveröffentlichung wird dort abgeschlossen.
 * Das ist weder Fehler noch Erfolg: Status manuelle_freigabe_erforderlich,
 * Ergebnis ok mit Warnung, Bearbeitungsstatus bleibt bereit. Ein späteres
 * Rücklesen mit onlineSince setzt aktiv.
 *
 * Deaktivierung (Masterprompt Abschnitt 24): withdraw() setzt
 * deaktivierung_angefordert, das Rücklesen ohne Eintrag deaktivierung_bestaetigt.
 * Ältere Zeilen mit zurueckgezogen bleiben lesbar und gelten wie bestätigt.
 *
 * Jeder Statuswechsel läuft über PortalStatusTransition (Nachweisquelle,
 * Zeitpunkt, Freigabeversion, Benutzer; Masterprompt Abschnitt 21).
 *
 * Nach Prüfbericht 2026-09-11:
 * - Befund 1: Ein Portal zählt erst als angefordert, wenn POST /publish ohne
 *   Ausnahme beantwortet und nicht als Fehler ausgewertet wurde. Ohne
 *   erfolgreiche Anforderung bleibt listings.status unverändert.
 * - Befund 15: Ist die Bildübertragung nach dem synchronen Zeitlimit noch
 *   offen, wird nicht veröffentlicht; der Job trägt die Freigabeversion und
 *   fordert die Veröffentlichung nach vollständiger Übertragung an, sofern
 *   bis dahin keine Deaktivierung angefordert wurde.
 */
final class FlowfactPublishingService implements PublishingService
{
    public const string HINWEIS_UNBEKANNT = 'Status nicht ermittelbar, in FLOWFACT prüfen';

    public const string MELDUNG_BILDER_OFFEN = 'Die Bildübertragung ist noch nicht abgeschlossen, die restlichen Bilder werden im Hintergrund übertragen. Die Veröffentlichung wird danach automatisch für die freigegebenen Portale angefordert; der Portalstatus zeigt den Stand.';

    public const string MELDUNG_KEIN_PORTAL_ANGEFORDERT = 'Für kein Portal konnte die Veröffentlichung angefordert werden. Der Objektstatus bleibt unverändert.';

    public const string MELDUNG_MANUELLE_FREIGABE = 'Objekt ist vollständig in FLOWFACT vorbereitet. Portalveröffentlichung in FLOWFACT abschließen.';

    public const string MELDUNG_MANUELLE_DEAKTIVIERUNG = 'Die Deaktivierung konnte über die Schnittstelle nicht ausgelöst werden (keine Berechtigung). Bitte das Portal in FLOWFACT offline nehmen.';

    public const string MELDUNG_PORTALE_NICHT_FREIGEGEBEN = 'Die gewählten Portale sind nicht Teil der jüngsten Freigabe (%s). Bitte im Schritt Prüfen und veröffentlichen erneut freigeben.';

    public const string ERGEBNIS_ANGEFORDERT = 'angefordert';

    public const string ERGEBNIS_MANUELL = 'manuelle Freigabe erforderlich';

    public function __construct(
        private readonly TokenProvider $tokenProvider,
        private readonly PortalService $portale,
        private readonly ListingSyncService $syncService,
        private readonly CompletenessCheck $completeness,
        private readonly ListingStatusMachine $statusMachine,
        private readonly ReleaseService $releases,
        private readonly TokenScrubber $scrubber,
        private readonly int $syncZeitlimitSekunden = 25,
        private readonly int $unbekanntNachMinuten = 30,
    ) {}

    public function isConfigured(): bool
    {
        return $this->tokenProvider->token() !== null;
    }

    public function portals(): array
    {
        $ergebnis = [];

        foreach ($this->portale->portals() as $portal) {
            if (! isset($portal['id']) || ! is_scalar($portal['id'])) {
                continue;
            }

            $ergebnis[] = new PortalInfo(
                id: (string) $portal['id'],
                name: (string) ($portal['name'] ?? $portal['portalType'] ?? $portal['id']),
                type: (string) ($portal['portalType'] ?? ''),
                authenticated: (bool) ($portal['authenticated'] ?? false),
            );
        }

        return $ergebnis;
    }

    public function transfer(Listing $listing, ?User $user = null): SyncResult
    {
        return $this->syncService->sync($listing, $user, false, $this->syncZeitlimitSekunden);
    }

    public function publish(Listing $listing, array $portalIds, ?User $user = null): PublishResult
    {
        $vorpruefung = $this->vorpruefung($listing, $portalIds, $user, 'publish');

        if ($vorpruefung !== null) {
            return $vorpruefung;
        }

        $release = $this->releases->latest($listing);

        if ($release === null) {
            return new PublishResult(false, ListingSyncService::MELDUNG_KEINE_FREIGABE);
        }

        $portalIds = array_values(array_unique(array_map('strval', $portalIds)));
        $nichtFreigegeben = array_values(array_diff($portalIds, $release->portalIds()));

        if ($nichtFreigegeben !== []) {
            return new PublishResult(false, sprintf(self::MELDUNG_PORTALE_NICHT_FREIGEGEBEN, implode(', ', $nichtFreigegeben)));
        }

        $warnungen = [];
        $sync = $this->stelleUebertragungSicher($listing, $release, $user, $warnungen);

        if ($sync !== null) {
            return $sync;
        }

        $link = $listing->flowfactLink()->first();
        $entityId = (string) $link->flowfact_entity_id;
        $schema = (string) $link->flowfact_schema;
        $showAddress = $this->syncService->payloadFuer($release)->showAddress;
        $portalService = $this->portale->scoped($listing, $user);

        try {
            $bekannt = $this->portalIndex($portalService);
        } catch (FlowfactException $exception) {
            return new PublishResult(false, 'Portale konnten nicht gelesen werden: '.$exception->getMessage(), $warnungen, $exception);
        }

        $jePortal = [];
        $fehler = [];
        $manuell = [];
        $angefordert = 0;
        $rueckleseNoetig = false;

        foreach ($portalIds as $portalId) {
            $portal = $bekannt[$portalId] ?? null;

            if ($portal === null || ! $portal->authenticated) {
                $fehler[] = sprintf('Portal "%s" ist in FLOWFACT nicht bekannt oder nicht authentifiziert.', $portalId);
                $jePortal[$portalId] = 'Fehler: in FLOWFACT nicht bekannt oder nicht authentifiziert';

                continue;
            }

            $publication = $this->publikation($listing, $portal->id, $portal->name);
            $publication->angefordert_at = Carbon::now();
            $publication->bestaetigt_at = null;
            $publication->zurueckgezogen_at = null;
            $publication->letzter_fehler = null;
            PortalStatusTransition::apply($publication, PortalStatus::Angefordert, PortalStatusTransition::QUELLE_PUBLISH_ANGEFORDERT, $release, $user);

            try {
                $antwort = $portalService->publish($this->publishRequest($portal, $entityId, $schema, 'ONLINE', $showAddress));
            } catch (AuthenticationException) {
                // Fall B: Übertragung und Portalliste haben funktioniert, nur das
                // Veröffentlichungsrecht fehlt dem API-Benutzer.
                $this->setzeManuelleFreigabe($publication, PortalStatusTransition::QUELLE_PUBLISH_NICHT_AUTORISIERT, $release, $user);
                $manuell[] = $portal->name;
                $jePortal[$portal->name] = self::ERGEBNIS_MANUELL;

                continue;
            } catch (FlowfactException $exception) {
                $this->setzeFehler($publication, $exception->getMessage(), $release, $user);
                $fehler[] = sprintf('%s: %s', $portal->name, $exception->getMessage());
                $jePortal[$portal->name] = 'Fehler: '.$exception->getMessage();

                continue;
            }

            $auswertung = $this->werteAntwortAus($publication, $antwort, $entityId, $portal->id, $release, $user);

            if ($auswertung === 'ohne_recht') {
                $manuell[] = $portal->name;
                $jePortal[$portal->name] = self::ERGEBNIS_MANUELL;

                continue;
            }

            if ($auswertung === 'fehler') {
                $fehler[] = sprintf('%s: %s', $portal->name, (string) $publication->letzter_fehler);
                $jePortal[$portal->name] = 'Fehler: '.(string) $publication->letzter_fehler;

                continue;
            }

            // Befund 1: erst jetzt gilt das Portal als angefordert.
            $angefordert++;
            $jePortal[$portal->name] = self::ERGEBNIS_ANGEFORDERT;

            if ($auswertung === 'transferiert') {
                $rueckleseNoetig = true;
            }
        }

        if ($angefordert > 0) {
            $this->nachVeroeffentlicht($listing, $warnungen);
        }

        if ($rueckleseNoetig) {
            $this->rueckleseSicher($listing, $warnungen);
        }

        $listing->unsetRelation('portalPublications');

        if ($manuell !== []) {
            $warnungen[] = self::MELDUNG_MANUELLE_FREIGABE;
        }

        $liste = $this->portalliste($jePortal);
        $ergebnis = fn (bool $ok, string $meldung): PublishResult => new PublishResult(
            $ok,
            $meldung,
            array_values(array_unique($warnungen)),
            null,
            $angefordert,
            $manuell,
            $jePortal,
        );

        if ($angefordert === 0 && $manuell === []) {
            return $ergebnis(false, self::MELDUNG_KEIN_PORTAL_ANGEFORDERT.' '.implode(' ', $fehler));
        }

        if ($fehler !== []) {
            return $ergebnis(true, 'Veröffentlichung teilweise fehlgeschlagen: '.$liste.'.');
        }

        if ($manuell !== [] && $angefordert === 0) {
            return $ergebnis(true, self::MELDUNG_MANUELLE_FREIGABE.' '.$liste.'.');
        }

        return $ergebnis(true, sprintf('Veröffentlichung für %d Portal(e) angefordert: %s. Der Portalstatus wird nach Bestätigung durch FLOWFACT aktualisiert.', $angefordert, $liste));
    }

    public function withdraw(Listing $listing, array $portalIds, ?User $user = null): PublishResult
    {
        if ($user !== null && Gate::forUser($user)->denies('withdraw', $listing)) {
            return new PublishResult(false, 'Sie sind nicht berechtigt, dieses Objekt zurückzuziehen.');
        }

        $link = $listing->flowfactLink()->first();

        if ($link === null || $link->flowfact_entity_id === null) {
            return new PublishResult(false, 'Das Objekt ist in FLOWFACT nicht bekannt, es gibt nichts zurückzuziehen.');
        }

        $release = $this->releases->latest($listing);
        $showAddress = $release !== null ? $this->syncService->payloadFuer($release)->showAddress : (bool) ($listing->adresse_im_inserat_anzeigen ?? true);
        $portalService = $this->portale->scoped($listing, $user);
        $warnungen = [];
        $fehler = [];
        $manuell = [];
        $jePortal = [];
        $angefordert = 0;
        $rueckleseNoetig = false;

        try {
            $bekannt = $this->portalIndex($portalService);
        } catch (FlowfactException $exception) {
            return new PublishResult(false, 'Portale konnten nicht gelesen werden: '.$exception->getMessage(), [], $exception);
        }

        foreach (array_values(array_unique(array_map('strval', $portalIds))) as $portalId) {
            $publication = ListingPortalPublication::query()
                ->where('listing_id', $listing->id)
                ->where('portal_id', $portalId)
                ->first();

            if ($publication === null || in_array($publication->status, [PortalStatus::NichtVeroeffentlicht, PortalStatus::Zurueckgezogen, PortalStatus::DeaktivierungBestaetigt], true)) {
                $warnungen[] = sprintf('Für Portal "%s" liegt keine aktive Veröffentlichung vor.', $portalId);

                continue;
            }

            $portal = $bekannt[$portalId] ?? new PortalInfo($portalId, $publication->portal_name, '', true);

            $publication->zurueckgezogen_at = Carbon::now();
            $publication->letzter_fehler = null;
            PortalStatusTransition::apply($publication, PortalStatus::DeaktivierungAngefordert, PortalStatusTransition::QUELLE_OFFLINE_ANGEFORDERT, $release, $user);
            $angefordert++;

            try {
                $antwort = $portalService->publish($this->publishRequest($portal, (string) $link->flowfact_entity_id, (string) $link->flowfact_schema, 'OFFLINE', $showAddress));
            } catch (AuthenticationException) {
                // Fall B bei der Deaktivierung: Anforderung bleibt stehen, Abschluss in FLOWFACT.
                $publication->letzter_fehler = self::MELDUNG_MANUELLE_DEAKTIVIERUNG;
                $publication->save();
                $manuell[] = $publication->portal_name;
                $jePortal[$publication->portal_name] = self::ERGEBNIS_MANUELL;

                continue;
            } catch (FlowfactException $exception) {
                $this->setzeFehler($publication, $exception->getMessage(), $release, $user);
                $fehler[] = sprintf('%s: %s', $publication->portal_name, $exception->getMessage());
                $jePortal[$publication->portal_name] = 'Fehler: '.$exception->getMessage();

                continue;
            }

            $auswertung = $this->werteAntwortAus($publication, $antwort, (string) $link->flowfact_entity_id, $portalId, $release, $user);

            if ($auswertung === 'fehler') {
                $fehler[] = sprintf('%s: %s', $publication->portal_name, (string) $publication->letzter_fehler);
                $jePortal[$publication->portal_name] = 'Fehler: '.(string) $publication->letzter_fehler;

                continue;
            }

            $jePortal[$publication->portal_name] = 'Deaktivierung angefordert';

            // Nach einer synchron bestätigten Übertragung oder bei einer
            // Publikation, die nie online war, wird sofort nachgelesen.
            if ($auswertung === 'transferiert' || $publication->bestaetigt_at === null) {
                $rueckleseNoetig = true;
            }
        }

        if ($rueckleseNoetig) {
            $this->rueckleseSicher($listing, $warnungen);
        }

        $listing->unsetRelation('portalPublications');

        if ($manuell !== []) {
            $warnungen[] = self::MELDUNG_MANUELLE_DEAKTIVIERUNG;
        }

        $ergebnis = fn (bool $ok, string $meldung): PublishResult => new PublishResult($ok, $meldung, array_values(array_unique($warnungen)), null, $angefordert, $manuell, $jePortal);

        if ($fehler !== []) {
            return $ergebnis(false, 'Rückzug teilweise fehlgeschlagen: '.$this->portalliste($jePortal).'.');
        }

        if ($angefordert === 0) {
            return $ergebnis(false, 'Es wurde kein Portal zurückgezogen.');
        }

        return $ergebnis(true, sprintf('Deaktivierung für %d Portal(e) angefordert: %s. Der Portalstatus wird nach Bestätigung durch FLOWFACT aktualisiert.', $angefordert, $this->portalliste($jePortal)));
    }

    /**
     * Rücklesen des Ist-Zustands. Einzige Stelle, die "aktiv" und
     * "deaktivierung_bestaetigt" setzt.
     */
    public function refreshStatus(Listing $listing): void
    {
        $link = $listing->flowfactLink()->first();

        if ($link === null || $link->flowfact_entity_id === null) {
            return;
        }

        $publications = ListingPortalPublication::query()->where('listing_id', $listing->id)->get();

        if ($publications->isEmpty()) {
            return;
        }

        $eintraege = [];

        foreach ($this->portale->scoped($listing)->estatePortals((string) $link->flowfact_entity_id) as $eintrag) {
            if (isset($eintrag['portalId']) && is_scalar($eintrag['portalId'])) {
                $eintraege[(string) $eintrag['portalId']] = $eintrag;
            }
        }

        $jetzt = Carbon::now();

        foreach ($publications as $publication) {
            $eintrag = $eintraege[$publication->portal_id] ?? null;
            $online = $eintrag !== null && ! empty($eintrag['onlineSince']);
            $release = $publication->release_id !== null ? ListingRelease::query()->find($publication->release_id) : null;

            if ($online) {
                if ($publication->status === PortalStatus::DeaktivierungAngefordert) {
                    // Deaktivierung angefordert, FLOWFACT meldet noch online: warten.
                    $this->beruehre($publication, $jetzt);
                } elseif ($publication->status !== PortalStatus::Aktiv) {
                    // Einzige Quelle für "aktiv": auch aus manuelle_freigabe_erforderlich
                    // (Abschluss in FLOWFACT) und aus älteren zurückgezogenen Zeilen.
                    $publication->bestaetigt_at = $jetzt;
                    $publication->letzter_fehler = null;
                    PortalStatusTransition::apply($publication, PortalStatus::Aktiv, PortalStatusTransition::QUELLE_RUECKLESEN_ONLINE, $release);
                } else {
                    $this->beruehre($publication, $jetzt);
                }

                continue;
            }

            if ($publication->status === PortalStatus::DeaktivierungAngefordert) {
                $this->bestaetigeDeaktivierung($publication, $eintrag === null, $release);

                continue;
            }

            // Ältere Zeilen (vor Welle 3): Rückzug mit zurueckgezogen_at ohne Statuswechsel.
            if ($publication->zurueckgezogen_at !== null && in_array($publication->status, [PortalStatus::Aktiv, PortalStatus::Angefordert, PortalStatus::Unbekannt], true)) {
                $publication->letzter_fehler = null;
                PortalStatusTransition::apply($publication, PortalStatus::DeaktivierungBestaetigt, PortalStatusTransition::QUELLE_RUECKLESEN_OHNE_EINTRAG, $release);

                continue;
            }

            if ($publication->zurueckgezogen_at !== null && $publication->status === PortalStatus::Fehler && $eintrag === null) {
                // Befund 1: gescheiterte Anforderung, in FLOWFACT nicht vorhanden,
                // nach Rückzug lokal wieder "nicht veröffentlicht".
                $publication->letzter_fehler = null;
                PortalStatusTransition::apply($publication, PortalStatus::NichtVeroeffentlicht, PortalStatusTransition::QUELLE_RUECKLESEN_OHNE_EINTRAG, $release);

                continue;
            }

            if ($publication->status === PortalStatus::Angefordert && $this->istUeberfaellig($publication, $jetzt)) {
                $publication->letzter_fehler = self::HINWEIS_UNBEKANNT;
                PortalStatusTransition::apply($publication, PortalStatus::Unbekannt, PortalStatusTransition::QUELLE_UEBERFAELLIG, $release);

                continue;
            }

            $this->beruehre($publication, $jetzt);
        }

        $this->gleicheListingStatusAb($listing, $publications->fresh());
    }

    /**
     * Angeforderte Veröffentlichungen ohne Rücklesen nach 30 Minuten als
     * unbekannt markieren. Wird auch dann angewendet, wenn das Rücklesen
     * selbst scheitert (flow:portal-status).
     */
    public function markiereUeberfaellige(Listing $listing): int
    {
        $jetzt = Carbon::now();
        $anzahl = 0;

        foreach (ListingPortalPublication::query()->where('listing_id', $listing->id)->where('status', PortalStatus::Angefordert->value)->get() as $publication) {
            if ($this->istUeberfaellig($publication, $jetzt)) {
                $publication->letzter_fehler = self::HINWEIS_UNBEKANNT;
                PortalStatusTransition::apply($publication, PortalStatus::Unbekannt, PortalStatusTransition::QUELLE_UEBERFAELLIG);
                $anzahl++;
            }
        }

        return $anzahl;
    }

    /**
     * @param  list<string>  $portalIds
     */
    private function vorpruefung(Listing $listing, array $portalIds, ?User $user, string $faehigkeit): ?PublishResult
    {
        if (! in_array($listing->status, [ListingStatus::Bereit, ListingStatus::Veroeffentlicht, ListingStatus::Zurueckgezogen], true)) {
            return new PublishResult(false, 'Nur Objekte im Status bereit, veröffentlicht oder zurückgezogen können veröffentlicht werden. Entwürfe sind ausgeschlossen.');
        }

        if ($user !== null && Gate::forUser($user)->denies($faehigkeit, $listing)) {
            return new PublishResult(false, 'Sie sind nicht berechtigt, dieses Objekt zu veröffentlichen.');
        }

        if ($portalIds === []) {
            return new PublishResult(false, 'Bitte wählen Sie mindestens ein Portal aus.');
        }

        $listing->loadMissing(['price', 'energy', 'media']);
        $ergebnis = $this->completeness->check($listing);

        if (! $ergebnis->istVollstaendig()) {
            return new PublishResult(false, 'Das Objekt ist nicht vollständig: '.implode(', ', $ergebnis->fehlend).'.');
        }

        return null;
    }

    /**
     * Veröffentlichung setzt eine erfolgreiche Übertragung der Freigabeversion
     * voraus, sonst wird zuerst übertragen.
     *
     * @param  list<string>  $warnungen
     */
    private function stelleUebertragungSicher(Listing $listing, ListingRelease $release, ?User $user, array &$warnungen): ?PublishResult
    {
        $link = $listing->flowfactLink()->first();

        $aktuell = $link !== null
            && $link->flowfact_entity_id !== null
            && $link->sync_status === SyncStatus::Uebertragen
            && $link->uebertragener_inhalt_hash === $release->inhalt_hash;

        if ($aktuell) {
            return null;
        }

        $sync = $this->syncService->sync($listing, $user, false, $this->syncZeitlimitSekunden);

        if (! $sync->ok) {
            return new PublishResult(false, 'Die Übertragung an FLOWFACT ist fehlgeschlagen: '.$sync->meldung, $sync->warnungen, $sync->ausnahme);
        }

        $warnungen = array_merge($warnungen, $sync->warnungen);
        $link = $listing->flowfactLink()->first();

        if ($link === null || $link->flowfact_entity_id === null) {
            return new PublishResult(false, 'Die FLOWFACT-Entität ist nach der Übertragung nicht bekannt.', $warnungen);
        }

        // Befund 15: Zeitlimit beim Bildupload erreicht, der Rest läuft als Job.
        // Ein Inserat mit unvollständigem Bildsatz geht nicht online; der Job
        // fordert die Veröffentlichung nach Abschluss an (ReleaseGuard).
        if ($link->sync_status !== SyncStatus::Uebertragen) {
            return new PublishResult(false, self::MELDUNG_BILDER_OFFEN, $warnungen);
        }

        return null;
    }

    private function publikation(Listing $listing, string $portalId, string $portalName): ListingPortalPublication
    {
        $publication = ListingPortalPublication::query()->firstOrNew(
            ['listing_id' => $listing->id, 'portal_id' => $portalId],
            ['status' => PortalStatus::NichtVeroeffentlicht],
        );

        $publication->portal_name = $portalName;

        if (! $publication->exists) {
            $publication->save();
        }

        return $publication;
    }

    /**
     * @return array<string, PortalInfo>
     */
    private function portalIndex(PortalService $portalService): array
    {
        $index = [];

        foreach ($portalService->portals() as $portal) {
            if (isset($portal['id']) && is_scalar($portal['id'])) {
                $index[(string) $portal['id']] = new PortalInfo(
                    id: (string) $portal['id'],
                    name: (string) ($portal['name'] ?? $portal['portalType'] ?? $portal['id']),
                    type: (string) ($portal['portalType'] ?? ''),
                    authenticated: (bool) ($portal['authenticated'] ?? false),
                );
            }
        }

        return $index;
    }

    /**
     * @return array<string, mixed>
     */
    private function publishRequest(PortalInfo $portal, string $entityId, string $schema, string $targetStatus, bool $showAddress): array
    {
        $request = [
            'portalId' => $portal->id,
            'publishType' => 'MANUAL',
            'entries' => [[
                'entityId' => $entityId,
                'schema' => $schema,
                'targetStatus' => $targetStatus,
                'showAddress' => $showAddress,
            ]],
        ];

        if ($portal->type !== '') {
            $request['portalType'] = $portal->type;
        }

        return $request;
    }

    /**
     * Antwort von POST /publish auswerten (flowfact-api.md Abschnitt 6, Schritt 6).
     *
     * @param  array<string, mixed>|null  $antwort
     * @return 'offen'|'fehler'|'transferiert'|'ohne_recht'
     */
    private function werteAntwortAus(ListingPortalPublication $publication, ?array $antwort, string $entityId, string $portalId, ?ListingRelease $release, ?User $user): string
    {
        if ($antwort === null) {
            // Leerer Körper: asynchron angenommen, Ergebnis später nachlesen.
            return 'offen';
        }

        // Fall B aus der Antwort (Bulk-Form, defensiv auch bei /publish): das
        // Portal steht unter portalsWithoutAccessRights.
        $ohneRecht = $antwort['portalsWithoutAccessRights'] ?? null;

        if (is_array($ohneRecht) && in_array($portalId, array_map('strval', array_filter($ohneRecht, 'is_scalar')), true)) {
            $this->setzeManuelleFreigabe($publication, PortalStatusTransition::QUELLE_PUBLISH_OHNE_PORTALRECHT, $release, $user);

            return 'ohne_recht';
        }

        $fehler = $this->eintraegeFuer($antwort['errors'] ?? null, $entityId, $portalId);

        if ($fehler !== []) {
            $this->setzeFehler($publication, $this->fehlertext($fehler), $release, $user);

            return 'fehler';
        }

        if ($this->eintraegeFuer($antwort['successFullyTransfered'] ?? null, $entityId, $portalId) !== []) {
            return 'transferiert';
        }

        return 'offen';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eintraegeFuer(mixed $liste, string $entityId, string $portalId): array
    {
        if (! is_array($liste)) {
            return [];
        }

        $treffer = [];

        foreach ($liste as $eintrag) {
            if (! is_array($eintrag)) {
                continue;
            }

            $passtEntity = ! isset($eintrag['entityId']) || (string) $eintrag['entityId'] === $entityId;
            $passtPortal = ! isset($eintrag['portalId']) || (string) $eintrag['portalId'] === $portalId;

            if ($passtEntity && $passtPortal) {
                $treffer[] = $eintrag;
            }
        }

        return $treffer;
    }

    /**
     * @param  list<array<string, mixed>>  $eintraege
     */
    private function fehlertext(array $eintraege): string
    {
        $texte = [];

        foreach ($eintraege as $eintrag) {
            foreach ((array) ($eintrag['detailedMessages'] ?? []) as $detail) {
                $uebersetzt = is_array($detail) ? ($detail['validationError']['translatedMessage'] ?? null) : null;
                $original = is_array($detail) ? ($detail['originalMessage'] ?? null) : null;

                if (is_string($uebersetzt) && $uebersetzt !== '') {
                    $texte[] = $uebersetzt;
                } elseif (is_string($original) && $original !== '') {
                    $texte[] = $original;
                }
            }

            if ($texte === []) {
                foreach ((array) ($eintrag['messages'] ?? []) as $nachricht) {
                    if (is_string($nachricht) && $nachricht !== '') {
                        $texte[] = $nachricht;
                    }
                }
            }
        }

        $text = implode(' ', array_unique($texte));

        return $text !== '' ? $text : 'FLOWFACT hat einen Fehler gemeldet.';
    }

    private function setzeFehler(ListingPortalPublication $publication, string $meldung, ?ListingRelease $release = null, ?User $user = null): void
    {
        $publication->letzter_fehler = mb_substr((string) $this->scrubber->scrub($meldung), 0, 1000);
        PortalStatusTransition::apply($publication, PortalStatus::Fehler, PortalStatusTransition::QUELLE_PUBLISH_FEHLER, $release, $user);
    }

    private function setzeManuelleFreigabe(ListingPortalPublication $publication, string $quelle, ?ListingRelease $release, ?User $user): void
    {
        $publication->letzter_fehler = self::MELDUNG_MANUELLE_FREIGABE;
        PortalStatusTransition::apply($publication, PortalStatus::ManuelleFreigabeErforderlich, $quelle, $release, $user);
    }

    /**
     * Rücklesen ohne onlineSince nach angeforderter Deaktivierung. Eine
     * Publikation, die vor dem Rückzug nur "fehler" war und nie in FLOWFACT
     * existierte, wird lokal wieder "nicht veröffentlicht" (Befund 1); alle
     * anderen gelten als bestätigt deaktiviert.
     */
    private function bestaetigeDeaktivierung(ListingPortalPublication $publication, bool $ohneEintrag, ?ListingRelease $release): void
    {
        $vorher = ListingPortalStatusLog::query()
            ->where('publication_id', $publication->getKey())
            ->where('nach_status', PortalStatus::DeaktivierungAngefordert->value)
            ->orderByDesc('id')
            ->first()?->von_status;

        $publication->letzter_fehler = null;

        if ($ohneEintrag && $publication->bestaetigt_at === null && $vorher === PortalStatus::Fehler) {
            PortalStatusTransition::apply($publication, PortalStatus::NichtVeroeffentlicht, PortalStatusTransition::QUELLE_RUECKLESEN_OHNE_EINTRAG, $release);

            return;
        }

        PortalStatusTransition::apply($publication, PortalStatus::DeaktivierungBestaetigt, PortalStatusTransition::QUELLE_RUECKLESEN_OHNE_EINTRAG, $release);
    }

    private function beruehre(ListingPortalPublication $publication, Carbon $jetzt): void
    {
        $publication->letzte_pruefung_at = $jetzt;
        $publication->save();
    }

    /**
     * @param  array<string, string>  $jePortal
     */
    private function portalliste(array $jePortal): string
    {
        $teile = [];

        foreach ($jePortal as $portal => $ergebnis) {
            $teile[] = $portal.': '.$ergebnis;
        }

        return implode(', ', $teile);
    }

    /**
     * @param  list<string>  $warnungen
     */
    private function nachVeroeffentlicht(Listing $listing, array &$warnungen): void
    {
        try {
            if ($listing->status === ListingStatus::Zurueckgezogen) {
                $this->statusMachine->transition($listing, ListingStatus::Bereit);
            }

            if ($listing->status === ListingStatus::Bereit) {
                $this->statusMachine->transition($listing, ListingStatus::Veroeffentlicht);
            }
        } catch (IllegalStatusTransitionException $exception) {
            $warnungen[] = $exception->getMessage();
        }
    }

    /**
     * @param  list<string>  $warnungen
     */
    private function rueckleseSicher(Listing $listing, array &$warnungen): void
    {
        try {
            $this->refreshStatus($listing);
        } catch (Throwable $exception) {
            $warnungen[] = 'Der Portalstatus konnte noch nicht nachgelesen werden: '.$this->scrubber->scrub($exception->getMessage());
        }
    }

    private function istUeberfaellig(ListingPortalPublication $publication, Carbon $jetzt): bool
    {
        return $publication->angefordert_at !== null
            && $publication->angefordert_at->copy()->addMinutes($this->unbekanntNachMinuten)->lessThanOrEqualTo($jetzt);
    }

    /**
     * listings.status nur über die Statusmaschine (Befund 1): Solange eine
     * Publikation angefordert, aktiv oder mit angeforderter Deaktivierung
     * offen ist, bleibt veroeffentlicht. Sind alle Publikationen fehler,
     * unbekannt, manuelle_freigabe_erforderlich, deaktiviert (bestätigt oder
     * ältere zurueckgezogen) oder nicht_veroeffentlicht, geht das Objekt nach
     * zurueckgezogen, wenn mindestens ein Portal nach bestätigter Aktivität
     * deaktiviert wurde, sonst nach bereit. Ist ein Portal aktiv, während das
     * Objekt bereit oder zurückgezogen ist, wird es veroeffentlicht.
     *
     * @param  Collection<int, ListingPortalPublication>  $publications
     */
    private function gleicheListingStatusAb(Listing $listing, $publications): void
    {
        $listing->refresh();

        $offen = $publications->contains(fn (ListingPortalPublication $p): bool => in_array($p->status, [PortalStatus::Aktiv, PortalStatus::Angefordert, PortalStatus::DeaktivierungAngefordert], true));
        $aktiv = $publications->contains(fn (ListingPortalPublication $p): bool => $p->status === PortalStatus::Aktiv);
        $nachAktivDeaktiviert = $publications->contains(fn (ListingPortalPublication $p): bool => in_array($p->status, [PortalStatus::Zurueckgezogen, PortalStatus::DeaktivierungBestaetigt], true) && $p->bestaetigt_at !== null);

        try {
            if ($listing->status === ListingStatus::Veroeffentlicht && ! $offen) {
                $this->statusMachine->transition($listing, $nachAktivDeaktiviert ? ListingStatus::Zurueckgezogen : ListingStatus::Bereit);
            } elseif ($listing->status === ListingStatus::Zurueckgezogen && $aktiv) {
                $this->statusMachine->transition($listing, ListingStatus::Bereit);
                $this->statusMachine->transition($listing, ListingStatus::Veroeffentlicht);
            } elseif ($listing->status === ListingStatus::Bereit && $aktiv) {
                $this->statusMachine->transition($listing, ListingStatus::Veroeffentlicht);
            }
        } catch (IllegalStatusTransitionException) {
            // Statuswechsel nicht zulässig: Portalstatus bleibt trotzdem korrekt.
        }
    }
}
