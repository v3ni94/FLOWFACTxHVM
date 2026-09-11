<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Domain\Listing\CompletenessCheck;
use App\Domain\Listing\IllegalStatusTransitionException;
use App\Domain\Listing\ListingContentHasher;
use App\Domain\Listing\ListingStatusMachine;
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
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Umsetzung der Schnittstelle zur Oberfläche (docs/connector.md Abschnitt 5,
 * Datenvertrag Abschnitt 4.3, ADR-004).
 *
 * WARUM Rücklesen vor "aktiv": Das Absenden von POST /publish beweist nur die
 * Annahme des Auftrags, bei asynchroner Verarbeitung kommt gar kein Körper.
 * "aktiv" wird ausschließlich aus GET /estates/{id}/portals mit onlineSince
 * gesetzt. Bis dahin zeigt die Oberfläche "Bestätigung ausstehend".
 *
 * Nach Prüfbericht 2026-09-11:
 * - Befund 1: Ein Portal zählt erst als angefordert, wenn POST /publish ohne
 *   Ausnahme beantwortet und nicht als Fehler ausgewertet wurde. Ohne
 *   erfolgreiche Anforderung bleibt listings.status unverändert. Sind alle
 *   Publikationen gescheitert oder zurückgezogen, führt das Rücklesen das
 *   Objekt nach bereit beziehungsweise zurueckgezogen zurück.
 * - Befund 15: Ist die Bildübertragung nach dem synchronen Zeitlimit noch
 *   offen, wird nicht veröffentlicht, sondern um erneutes Veröffentlichen
 *   nach Abschluss der Hintergrundübertragung gebeten.
 */
final class FlowfactPublishingService implements PublishingService
{
    public const string HINWEIS_UNBEKANNT = 'Status nicht ermittelbar, in FLOWFACT prüfen';

    public const string HINWEIS_RUECKZUG = 'Rückzug angefordert, Bestätigung ausstehend';

    public const string MELDUNG_BILDER_OFFEN = 'Die Bildübertragung ist noch nicht abgeschlossen, die restlichen Bilder werden im Hintergrund übertragen. Bitte veröffentlichen Sie das Objekt erneut, sobald der Übertragungsstatus "Übertragen" zeigt.';

    public const string MELDUNG_KEIN_PORTAL_ANGEFORDERT = 'Für kein Portal konnte die Veröffentlichung angefordert werden. Der Objektstatus bleibt unverändert.';

    public function __construct(
        private readonly TokenProvider $tokenProvider,
        private readonly PortalService $portale,
        private readonly ListingSyncService $syncService,
        private readonly CompletenessCheck $completeness,
        private readonly ListingStatusMachine $statusMachine,
        private readonly ListingContentHasher $hasher,
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

        $warnungen = [];
        $sync = $this->stelleUebertragungSicher($listing, $user, $warnungen);

        if ($sync !== null) {
            return $sync;
        }

        $link = $listing->flowfactLink()->first();
        $entityId = (string) $link->flowfact_entity_id;
        $schema = (string) $link->flowfact_schema;
        $portalService = $this->portale->scoped($listing, $user);

        try {
            $bekannt = $this->portalIndex($portalService);
        } catch (FlowfactException $exception) {
            return new PublishResult(false, 'Portale konnten nicht gelesen werden: '.$exception->getMessage(), $warnungen, $exception);
        }

        $fehler = [];
        $angefordert = 0;
        $rueckleseNoetig = false;

        foreach (array_values(array_unique($portalIds)) as $portalId) {
            $portal = $bekannt[$portalId] ?? null;

            if ($portal === null || ! $portal->authenticated) {
                $fehler[] = sprintf('Portal "%s" ist in FLOWFACT nicht bekannt oder nicht authentifiziert.', $portalId);

                continue;
            }

            $publication = ListingPortalPublication::query()->updateOrCreate(
                ['listing_id' => $listing->id, 'portal_id' => $portal->id],
                [
                    'portal_name' => $portal->name,
                    'status' => PortalStatus::Angefordert,
                    'angefordert_at' => Carbon::now(),
                    'bestaetigt_at' => null,
                    'zurueckgezogen_at' => null,
                    'letzter_fehler' => null,
                ],
            );

            try {
                $antwort = $portalService->publish($this->publishRequest($portal, $entityId, $schema, 'ONLINE', $link->listing->adresse_im_inserat_anzeigen ?? true));
            } catch (AuthenticationException $exception) {
                $this->setzeFehler($publication, AuthenticationException::MELDUNG);

                // Bereits erfolgreich angeforderte Portale zählen weiterhin.
                if ($angefordert > 0) {
                    $this->nachVeroeffentlicht($listing, $warnungen);
                }

                $listing->unsetRelation('portalPublications');

                return new PublishResult(false, AuthenticationException::MELDUNG, $warnungen, $exception);
            } catch (FlowfactException $exception) {
                $this->setzeFehler($publication, $exception->getMessage());
                $fehler[] = sprintf('%s: %s', $portal->name, $exception->getMessage());

                continue;
            }

            $auswertung = $this->werteAntwortAus($publication, $antwort, $entityId, $portal->id);

            if ($auswertung === 'fehler') {
                $fehler[] = sprintf('%s: %s', $portal->name, (string) $publication->letzter_fehler);

                continue;
            }

            // Befund 1: erst jetzt gilt das Portal als angefordert.
            $angefordert++;

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

        if ($fehler !== [] && $angefordert === 0) {
            return new PublishResult(false, self::MELDUNG_KEIN_PORTAL_ANGEFORDERT.' '.implode(' ', $fehler), $warnungen);
        }

        if ($fehler !== []) {
            return new PublishResult(true, 'Veröffentlichung teilweise fehlgeschlagen: '.implode(' ', $fehler), $warnungen);
        }

        return new PublishResult(true, sprintf('Veröffentlichung für %d Portal(e) angefordert. Der Portalstatus wird nach Bestätigung durch FLOWFACT aktualisiert.', $angefordert), $warnungen);
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

        $portalService = $this->portale->scoped($listing, $user);
        $warnungen = [];
        $fehler = [];
        $angefordert = 0;
        $rueckleseNoetig = false;

        try {
            $bekannt = $this->portalIndex($portalService);
        } catch (FlowfactException $exception) {
            return new PublishResult(false, 'Portale konnten nicht gelesen werden: '.$exception->getMessage(), [], $exception);
        }

        foreach (array_values(array_unique($portalIds)) as $portalId) {
            $publication = ListingPortalPublication::query()
                ->where('listing_id', $listing->id)
                ->where('portal_id', $portalId)
                ->first();

            if ($publication === null || in_array($publication->status, [PortalStatus::NichtVeroeffentlicht, PortalStatus::Zurueckgezogen], true)) {
                $warnungen[] = sprintf('Für Portal "%s" liegt keine aktive Veröffentlichung vor.', $portalId);

                continue;
            }

            $portal = $bekannt[$portalId] ?? new PortalInfo($portalId, $publication->portal_name, '', true);

            // Alter Status bleibt bis zum Rücklesen, der Hinweis zeigt den Rückzug an.
            $publication->zurueckgezogen_at = Carbon::now();
            $publication->letzter_fehler = self::HINWEIS_RUECKZUG;
            $publication->save();
            $angefordert++;

            try {
                $antwort = $portalService->publish($this->publishRequest($portal, (string) $link->flowfact_entity_id, (string) $link->flowfact_schema, 'OFFLINE', $listing->adresse_im_inserat_anzeigen ?? true));
            } catch (AuthenticationException $exception) {
                $this->setzeFehler($publication, AuthenticationException::MELDUNG);

                return new PublishResult(false, AuthenticationException::MELDUNG, $warnungen, $exception);
            } catch (FlowfactException $exception) {
                $this->setzeFehler($publication, $exception->getMessage());
                $fehler[] = sprintf('%s: %s', $publication->portal_name, $exception->getMessage());

                continue;
            }

            $warFehler = $publication->status === PortalStatus::Fehler;
            $auswertung = $this->werteAntwortAus($publication, $antwort, (string) $link->flowfact_entity_id, $portalId);

            if ($auswertung === 'fehler') {
                $fehler[] = sprintf('%s: %s', $publication->portal_name, (string) $publication->letzter_fehler);
            } elseif ($auswertung === 'transferiert' || $warFehler) {
                // Befund 1: eine reine Fehlerpublikation wird nach dem Rücklesen
                // lokal auf nicht_veroeffentlicht zurückgesetzt, wenn FLOWFACT
                // keinen Eintrag für sie kennt.
                $rueckleseNoetig = true;
            }
        }

        if ($rueckleseNoetig) {
            $this->rueckleseSicher($listing, $warnungen);
        }

        $listing->unsetRelation('portalPublications');

        if ($fehler !== []) {
            return new PublishResult(false, 'Rückzug teilweise fehlgeschlagen: '.implode(' ', $fehler), $warnungen);
        }

        if ($angefordert === 0) {
            return new PublishResult(false, 'Es wurde kein Portal zurückgezogen.', $warnungen);
        }

        return new PublishResult(true, sprintf('Rückzug für %d Portal(e) angefordert. Der Portalstatus wird nach Bestätigung durch FLOWFACT aktualisiert.', $angefordert), $warnungen);
    }

    /**
     * Rücklesen des Ist-Zustands. Einzige Stelle, die "aktiv" setzt.
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
            $publication->letzte_pruefung_at = $jetzt;

            if ($online) {
                if ($publication->status !== PortalStatus::Aktiv) {
                    $publication->status = PortalStatus::Aktiv;
                    $publication->bestaetigt_at = $jetzt;
                    $publication->letzter_fehler = $publication->zurueckgezogen_at !== null ? self::HINWEIS_RUECKZUG : null;
                }
            } elseif ($publication->zurueckgezogen_at !== null && in_array($publication->status, [PortalStatus::Aktiv, PortalStatus::Angefordert, PortalStatus::Unbekannt], true)) {
                $publication->status = PortalStatus::Zurueckgezogen;
                $publication->letzter_fehler = null;
            } elseif ($publication->zurueckgezogen_at !== null && $publication->status === PortalStatus::Fehler && $eintrag === null) {
                // Befund 1: gescheiterte Anforderung, in FLOWFACT nicht vorhanden,
                // nach Rückzug lokal wieder "nicht veröffentlicht".
                $publication->status = PortalStatus::NichtVeroeffentlicht;
                $publication->letzter_fehler = null;
            } elseif ($publication->status === PortalStatus::Angefordert && $this->istUeberfaellig($publication, $jetzt)) {
                $publication->status = PortalStatus::Unbekannt;
                $publication->letzter_fehler = self::HINWEIS_UNBEKANNT;
            }

            $publication->save();
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
                $publication->status = PortalStatus::Unbekannt;
                $publication->letzter_fehler = self::HINWEIS_UNBEKANNT;
                $publication->letzte_pruefung_at = $jetzt;
                $publication->save();
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
     * Veröffentlichung setzt eine erfolgreiche, aktuelle Übertragung voraus,
     * sonst wird zuerst übertragen.
     *
     * @param  list<string>  $warnungen
     */
    private function stelleUebertragungSicher(Listing $listing, ?User $user, array &$warnungen): ?PublishResult
    {
        $link = $listing->flowfactLink()->first();

        $aktuell = $link !== null
            && $link->flowfact_entity_id !== null
            && $link->sync_status === SyncStatus::Uebertragen
            && $link->uebertragener_inhalt_hash === $this->hasher->hash($listing);

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
        // Ein Inserat mit unvollständigem Bildsatz geht nicht online.
        if ($link->sync_status !== SyncStatus::Uebertragen) {
            return new PublishResult(false, self::MELDUNG_BILDER_OFFEN, $warnungen);
        }

        return null;
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
     * @return 'offen'|'fehler'|'transferiert'
     */
    private function werteAntwortAus(ListingPortalPublication $publication, ?array $antwort, string $entityId, string $portalId): string
    {
        if ($antwort === null) {
            // Leerer Körper: asynchron angenommen, Ergebnis später nachlesen.
            return 'offen';
        }

        $fehler = $this->eintraegeFuer($antwort['errors'] ?? null, $entityId, $portalId);

        if ($fehler !== []) {
            $this->setzeFehler($publication, $this->fehlertext($fehler));

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

    private function setzeFehler(ListingPortalPublication $publication, string $meldung): void
    {
        $publication->status = PortalStatus::Fehler;
        $publication->letzter_fehler = mb_substr((string) $this->scrubber->scrub($meldung), 0, 1000);
        $publication->save();
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
     * Publikation angefordert oder aktiv ist, bleibt veroeffentlicht. Sind
     * alle Publikationen fehler, unbekannt, zurueckgezogen oder
     * nicht_veroeffentlicht, geht das Objekt nach zurueckgezogen, wenn
     * mindestens ein Portal nach bestätigter Aktivität zurückgezogen wurde,
     * sonst nach bereit. Ist ein Portal aktiv, während das Objekt bereit
     * oder zurückgezogen ist, wird es veroeffentlicht.
     *
     * @param  Collection<int, ListingPortalPublication>  $publications
     */
    private function gleicheListingStatusAb(Listing $listing, $publications): void
    {
        $listing->refresh();

        $offen = $publications->contains(fn (ListingPortalPublication $p): bool => in_array($p->status, [PortalStatus::Aktiv, PortalStatus::Angefordert], true));
        $aktiv = $publications->contains(fn (ListingPortalPublication $p): bool => $p->status === PortalStatus::Aktiv);
        $nachAktivZurueckgezogen = $publications->contains(fn (ListingPortalPublication $p): bool => $p->status === PortalStatus::Zurueckgezogen && $p->bestaetigt_at !== null);

        try {
            if ($listing->status === ListingStatus::Veroeffentlicht && ! $offen) {
                $this->statusMachine->transition($listing, $nachAktivZurueckgezogen ? ListingStatus::Zurueckgezogen : ListingStatus::Bereit);
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
