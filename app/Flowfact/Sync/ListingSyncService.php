<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Domain\Listing\CompletenessCheck;
use App\Domain\Listing\ListingSnapshot;
use App\Domain\Listing\ReleaseService;
use App\Domain\Settings\SettingsRepository;
use App\Enums\ListingStatus;
use App\Enums\ReleaseAktion;
use App\Enums\SyncStatus;
use App\Enums\Vermarktungsart;
use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Client\Exceptions\FlowfactException;
use App\Flowfact\Client\Exceptions\NotFoundException;
use App\Flowfact\Client\TokenScrubber;
use App\Flowfact\Mapping\FlowfactPayloadMapper;
use App\Flowfact\Mapping\MappedPayload;
use App\Flowfact\Services\EntityService;
use App\Flowfact\Services\SearchService;
use App\Flowfact\Sync\Jobs\TransferListingJob;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\ListingRelease;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Ablauf einer Übertragung (docs/connector.md Abschnitt 3), idempotent
 * (Datenvertrag Grundsatz 4, ADR-005), auf Basis der jüngsten Freigabeversion
 * (Masterprompt Abschnitt 19 und 23, Masterprompt-Abgleich B.6).
 *
 * WARUM Freigabeversion: Der Payload entsteht aus payload_json und medien_json
 * der jüngsten ListingRelease, nie aus dem Live-Stand. Änderungen nach der
 * Freigabe erreichen FLOWFACT erst mit der nächsten Freigabe. Ohne Freigabe
 * wird nicht übertragen.
 *
 * WARUM Suche vor Anlegen: Nach einer Zeitüberschreitung eines POST ist
 * unbekannt, ob die Entität existiert. Der nächste Lauf sucht deshalb zuerst
 * über die Objektnummer (identifier). Ein zweites Anlegen ist damit
 * ausgeschlossen, solange die Suche funktioniert; liefert die Suche einen
 * Fehler statt eines leeren Ergebnisses, wird nicht angelegt.
 *
 * Konflikterkennung (Masterprompt Abschnitt 23): Müller FLOW ist das führende
 * System für die zugeordneten Felder, FLOWFACT für alles andere. Vor einem
 * PATCH wird der Änderungszeitpunkt der FLOWFACT-Entität mit dem nach der
 * letzten Übertragung gespeicherten verglichen. Weicht er ab, endet der Lauf
 * je nach Einstellung flowfact.konfliktverhalten mit einem Fehler (abbrechen)
 * oder überschreibt die zugeordneten Felder mit Warnung (ueberschreiben).
 *
 * Löschsemantik mit Absicht (Masterprompt Abschnitt 23): { "values": [] }
 * wird nur für zugeordnete Felder gesendet, die mit einer früheren Freigabe
 * tatsächlich übertragen wurden und in der aktuellen Freigabe leer sind.
 *
 * Nach Prüfbericht 2026-09-11:
 * - Befund 3: Ist eine Entität bekannt, gilt das im Link gespeicherte Schema.
 *   Ein Wechsel der Vermarktungsart nach der Übertragung wird abgelehnt statt
 *   eine zweite Entität im anderen Schema anzulegen. Jede Übertragung setzt
 *   die bestandene Vollständigkeitsprüfung voraus.
 * - Befund 7: Lease mit Token, Freigabe nur der eigenen Lease, Herzschlag
 *   nach jedem Bildupload.
 *
 * Nach Prüfbericht 2026-09-12:
 * - Befund 5: Die mit einer Übertragung tatsächlich gesendeten Zielfeldnamen
 *   werden am Link und an der Freigabeversion gespeichert
 *   (gesendete_felder_json). Die Löschliste des nächsten PATCH entsteht aus
 *   diesen Namen; eine zwischenzeitlich geänderte Feldzuordnung kann so
 *   weder ein nie gesendetes Zielfeld leeren noch das alte Zielfeld mit
 *   veraltetem Wert stehen lassen.
 * - Befund 10: Fehlt dem handelnden Benutzer das Veröffentlichungsrecht oder
 *   handelt kein Benutzer, wird die Entität mit status inactive gesendet und
 *   eine Warnung ausgegeben. Der gesendete Status steht im Link
 *   (flowfact_status); ein Wechsel des Status gilt als Inhaltsänderung, damit
 *   die Veröffentlichung durch einen Berechtigten die Entität aktiviert.
 */
final class ListingSyncService
{
    public const string SCHEMA_MIETE = 'flowfact.schema_miete';

    public const string SCHEMA_KAUF = 'flowfact.schema_kauf';

    public const string MELDUNG_MEHRFACHTREFFER = 'Mehrere Objekte mit dieser Nummer in FLOWFACT, bitte manuell klären';

    public const string MELDUNG_ENTWURF = 'Nur Objekte im Status bereit, veröffentlicht oder zurückgezogen werden an FLOWFACT übertragen. Entwürfe und archivierte Objekte sind ausgeschlossen.';

    public const string MELDUNG_SCHEMA_FEHLT = 'Kein FLOWFACT-Schema für %s hinterlegt. Bitte im Adminbereich unter FLOWFACT das Schema auswählen.';

    public const string MELDUNG_SCHEMAWECHSEL = 'Die Vermarktungsart wurde nach der Übertragung geändert. Bitte das Objekt in FLOWFACT manuell prüfen oder ein neues Objekt anlegen.';

    public const string MELDUNG_UNVOLLSTAENDIG = 'Das Objekt ist nicht vollständig und wird nicht übertragen. Es fehlen: %s.';

    public const string MELDUNG_KEINE_FREIGABE = 'Keine Freigabe vorhanden. Bitte im Schritt Prüfen und veröffentlichen freigeben.';

    public const string MELDUNG_KONFLIKT = 'In FLOWFACT wurde das Objekt seit der letzten Übertragung geändert (%s). Bitte prüfen und erneut freigeben.';

    public const string WARNUNG_KONFLIKT_UEBERSCHRIEBEN = 'In FLOWFACT wurde das Objekt seit der letzten Übertragung geändert (%s). Die zugeordneten Felder wurden gemäß Einstellung überschrieben.';

    public const string WARNUNG_KEIN_ZEITSTEMPEL = 'FLOWFACT hat keinen Änderungszeitpunkt geliefert; die Konflikterkennung ist beim nächsten Lauf nicht möglich.';

    public const string WARNUNG_INAKTIV_ANGELEGT = 'Entität inaktiv angelegt, da kein Veröffentlichungsrecht';

    /**
     * Einstellung (Standard true): geleerte Felder beim PATCH mit leerer
     * Werteliste senden, damit FLOWFACT den alten Wert löscht. Die genaue
     * Serversemantik ist am Konto zu verifizieren (flowfact-api.md Abschnitt 9).
     */
    public const string LEERE_FELDER_LOESCHEN = 'flowfact.leere_felder_loeschen';

    /**
     * Einstellung: abbrechen (Standard) oder ueberschreiben.
     */
    public const string KONFLIKTVERHALTEN = 'flowfact.konfliktverhalten';

    public const string KONFLIKT_ABBRECHEN = 'abbrechen';

    public const string KONFLIKT_UEBERSCHREIBEN = 'ueberschreiben';

    public function __construct(
        private readonly EntityService $entities,
        private readonly SearchService $search,
        private readonly MediaSyncService $media,
        private readonly FlowfactPayloadMapper $mapper,
        private readonly ReleaseService $releases,
        private readonly SettingsRepository $settings,
        private readonly SyncLease $lease,
        private readonly TokenScrubber $scrubber,
        private readonly CompletenessCheck $completeness = new CompletenessCheck,
    ) {}

    /**
     * @param  int|null  $zeitlimitSekunden  Zeitlimit des synchronen Wegs; offene Bilduploads laufen dann als Job weiter
     */
    public function sync(Listing $listing, ?User $user = null, bool $force = false, ?int $zeitlimitSekunden = null): SyncResult
    {
        if (! in_array($listing->status, [ListingStatus::Bereit, ListingStatus::Veroeffentlicht, ListingStatus::Zurueckgezogen], true)) {
            return SyncResult::failed(self::MELDUNG_ENTWURF);
        }

        $release = $this->releases->latest($listing);

        if ($release === null) {
            return SyncResult::failed(self::MELDUNG_KEINE_FREIGABE);
        }

        $listing->loadMissing(['price', 'energy', 'media']);
        $link = $this->linkFuer($listing);

        // Vollständigkeit in jedem Status (Befund 3): ein unvollständiges
        // Objekt geht nie an FLOWFACT, auch nicht als Aktualisierung.
        $vollstaendigkeit = $this->completeness->check($listing);

        if (! $vollstaendigkeit->istVollstaendig()) {
            return $this->fehlgeschlagen($link, sprintf(self::MELDUNG_UNVOLLSTAENDIG, implode(', ', $vollstaendigkeit->fehlend)));
        }

        // Schritt 5 (vorgezogen, ohne API-Aufruf): Payload aus der Freigabeversion.
        $snapshot = $release->snapshot();
        $payload = $this->mapper->mapSnapshot($snapshot);

        if ($payload->blockiert !== null) {
            return $this->fehlgeschlagen($link, $payload->blockiert);
        }

        // Schritt 1: Lease
        $token = $this->lease->acquire($link);

        if ($token === null) {
            return SyncResult::busy();
        }

        $deadline = $zeitlimitSekunden !== null ? Carbon::now()->addSeconds($zeitlimitSekunden) : null;

        try {
            // Schritt 2
            $link->sync_status = SyncStatus::UebertragungLaeuft;
            $link->save();

            // Schritt 3: Schema aus den Einstellungen, nie geraten. Bei
            // bekannter Entität gilt das gespeicherte Schema des Links.
            $abgeleitet = $this->schemaFuer($listing, $snapshot);

            if ($abgeleitet === null) {
                return $this->fehlgeschlagen($link, sprintf(self::MELDUNG_SCHEMA_FEHLT, $this->istMiete($listing, $snapshot) ? 'Miete' : 'Kauf'));
            }

            $schema = $this->gespeichertesSchema($link) ?? $abgeleitet;

            if ($schema !== $abgeleitet) {
                return $this->fehlgeschlagen($link, self::MELDUNG_SCHEMAWECHSEL);
            }

            $entities = $this->entities->scoped($listing, $user);
            $search = $this->search->scoped($listing, $user);

            // Schritt 4: Entität finden (liefert auch den FLOWFACT-Änderungszeitpunkt)
            $gefunden = $this->findeEntitaet($listing, $link, $schema, $entities, $search);

            if ($gefunden === false) {
                return $this->fehlgeschlagen($link, self::MELDUNG_MEHRFACHTREFFER);
            }

            $entityId = $gefunden['id'];
            $remoteLastModified = $gefunden['lastModified'];

            // Befund 10: Entitätsstatus nach Veröffentlichungsrecht, erst jetzt
            // bekannt, ob eine Entität angelegt oder aktualisiert wird.
            $payload = $this->mitEntitaetsstatus($payload, $user, $link, $entityId === null);
            $warnungen = $payload->warnungen;

            // Schritt 6: Anlegen oder Aktualisieren
            $angelegt = false;
            $inhaltGeaendert = $force
                || (int) ($link->release_id ?? 0) !== (int) $release->getKey()
                || $link->uebertragener_inhalt_hash !== $release->inhalt_hash
                || $this->statusGeaendert($link, $payload);

            if ($entityId === null) {
                $ergebnis = $entities->createMitMetadaten($schema, $payload->fields);
                $entityId = $ergebnis['id'];

                // ID sofort speichern, bevor irgendetwas anderes passiert.
                $link->flowfact_entity_id = $entityId;
                $link->flowfact_schema = $schema;
                $link->flowfact_last_modified = $ergebnis['lastModified'];
                $this->merkeGesendet($link, $release, $payload);
                $link->save();
                $angelegt = true;
                $inhaltGeaendert = true;

                if ($ergebnis['lastModified'] === null) {
                    $warnungen[] = self::WARNUNG_KEIN_ZEITSTEMPEL;
                }
            } elseif ($inhaltGeaendert) {
                // Konflikterkennung vor dem PATCH (Masterprompt Abschnitt 23).
                $konflikt = $this->konflikt($link, $remoteLastModified);

                if ($konflikt !== null) {
                    if ($this->konfliktverhalten() === self::KONFLIKT_ABBRECHEN) {
                        return $this->fehlgeschlagen($link, sprintf(self::MELDUNG_KONFLIKT, $konflikt));
                    }

                    $warnungen[] = sprintf(self::WARNUNG_KONFLIKT_UEBERSCHRIEBEN, $konflikt);
                }

                $patchPayload = $this->leereFelderLoeschen()
                    ? $this->mitLoeschungen($payload, $listing, $link, $release)
                    : $payload;

                $antwort = $entities->patch($schema, $entityId, $this->leereFelderLoeschen() ? $patchPayload->fieldsMitLoeschungen() : $patchPayload->fields);
                $lastModified = EntityService::lastModified($antwort);

                if ($lastModified === null) {
                    // Leerer oder verkürzter Antwortkörper: Änderungszeitpunkt
                    // nachlesen, sonst wäre die Konflikterkennung beim nächsten
                    // Lauf blind.
                    $lastModified = $this->lastModifiedNachlesen($schema, $entityId, $entities);
                }

                $link->flowfact_last_modified = $lastModified;
                $this->merkeGesendet($link, $release, $payload);
                $link->save();

                if ($lastModified === null) {
                    $warnungen[] = self::WARNUNG_KEIN_ZEITSTEMPEL;
                }
            }

            // Schritt 7: Medien
            $medienVollstaendig = true;
            $vorherige = $this->vorherigeVersion($listing, $link, $release);

            if ($inhaltGeaendert || $this->media->hatOffeneArbeit($listing, $snapshot)) {
                $heartbeat = function () use ($link, $token): void {
                    $this->lease->extend($link, $token);
                };

                $medien = $this->media->sync($listing, $snapshot, $schema, $entityId, $user, $deadline, $inhaltGeaendert, $heartbeat, $vorherige?->snapshot());
                $warnungen = array_merge($warnungen, $medien->warnungen);
                $medienVollstaendig = $medien->vollstaendig;
            }

            // Schritt 8: Abschluss
            $link->letzte_uebertragung_at = Carbon::now();
            $link->letzter_fehler = null;

            if ($medienVollstaendig) {
                $link->uebertragener_inhalt_hash = $release->inhalt_hash;
                $link->release_id = $release->getKey();
                $link->sync_status = SyncStatus::Uebertragen;
            } else {
                // Hash und Version bewusst nicht setzen: der Job soll den Rest übertragen.
                $link->sync_status = SyncStatus::GeaendertSeitUebertragung;
                $warnungen[] = 'Das Zeitlimit wurde erreicht, die restlichen Bilder werden im Hintergrund übertragen.';
            }

            $link->save();

            if (! $medienVollstaendig) {
                // Der Job trägt die Freigabeversion; eine Freigabe zur Veröffentlichung
                // wird nach vollständiger Übertragung im Job angefordert (Masterprompt 19).
                TransferListingJob::dispatch(
                    $listing->id,
                    $user?->id,
                    false,
                    (int) $release->getKey(),
                    $release->aktion === ReleaseAktion::Veroeffentlichen && $release->portalIds() !== [],
                )->afterCommit();
            }

            $meldung = match (true) {
                $angelegt => 'Objekt in FLOWFACT angelegt.',
                $inhaltGeaendert => 'Objekt in FLOWFACT aktualisiert.',
                default => 'Keine Änderungen seit der letzten Übertragung.',
            };

            return new SyncResult(true, $meldung, $entityId, array_values(array_unique($warnungen)), releaseId: (int) $release->getKey());
        } catch (AuthenticationException $exception) {
            // Schritt 9: keine automatische Wiederholung bei Auth-Fehlern
            return $this->fehlgeschlagen($link, AuthenticationException::MELDUNG, $exception);
        } catch (FlowfactException $exception) {
            return $this->fehlgeschlagen($link, $exception->getMessage(), $exception);
        } catch (Throwable $exception) {
            return $this->fehlgeschlagen($link, 'Unerwarteter Fehler bei der Übertragung: '.$exception->getMessage(), $exception);
        } finally {
            $this->lease->release($link, $token);
        }
    }

    /**
     * Schema aus den Einstellungen; die Vermarktungsart stammt aus der
     * Freigabeversion, sofern vorhanden, sonst aus dem Live-Stand.
     */
    public function schemaFuer(Listing $listing, ?ListingSnapshot $snapshot = null): ?string
    {
        $schema = $this->settings->get($this->istMiete($listing, $snapshot) ? self::SCHEMA_MIETE : self::SCHEMA_KAUF);

        return is_string($schema) && trim($schema) !== '' ? trim($schema) : null;
    }

    private function istMiete(Listing $listing, ?ListingSnapshot $snapshot): bool
    {
        $vermarktungsart = $snapshot?->listing['vermarktungsart'] ?? null;

        if (is_string($vermarktungsart) && Vermarktungsart::tryFrom($vermarktungsart) !== null) {
            return Vermarktungsart::from($vermarktungsart) === Vermarktungsart::Miete;
        }

        return $listing->istMiete();
    }

    /**
     * Payload der jüngsten Freigabe, ohne API-Aufruf (Vorschau, Smoke-Test).
     */
    public function payloadFuer(ListingRelease $release): MappedPayload
    {
        return $this->mapper->mapSnapshot($release->snapshot());
    }

    public function konfliktverhalten(): string
    {
        $wert = $this->settings->get(self::KONFLIKTVERHALTEN, self::KONFLIKT_ABBRECHEN);

        return is_string($wert) && strtolower(trim($wert)) === self::KONFLIKT_UEBERSCHREIBEN
            ? self::KONFLIKT_UEBERSCHREIBEN
            : self::KONFLIKT_ABBRECHEN;
    }

    /**
     * Schema des Links, sobald eine Entität bekannt ist (Befund 3).
     */
    private function gespeichertesSchema(ListingFlowfactLink $link): ?string
    {
        if ($link->flowfact_entity_id === null) {
            return null;
        }

        $schema = $link->flowfact_schema;

        return is_string($schema) && trim($schema) !== '' ? trim($schema) : null;
    }

    public function leereFelderLoeschen(): bool
    {
        $wert = $this->settings->get(self::LEERE_FELDER_LOESCHEN, true);

        return filter_var($wert, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * Konflikt: FLOWFACT meldet einen anderen Änderungszeitpunkt als den nach
     * der letzten Übertragung gespeicherten. Ohne gespeicherten oder ohne
     * gelieferten Zeitpunkt ist kein Vergleich möglich (kein Konflikt).
     *
     * @return string|null lesbarer Zeitpunkt der fremden Änderung
     */
    private function konflikt(ListingFlowfactLink $link, ?string $remoteLastModified): ?string
    {
        $gespeichert = $link->flowfact_last_modified;

        if ($gespeichert === null || $gespeichert === '' || $remoteLastModified === null || $remoteLastModified === '') {
            return null;
        }

        if ((string) $gespeichert === (string) $remoteLastModified) {
            return null;
        }

        return self::zeitpunktLesbar($remoteLastModified);
    }

    /**
     * Unix-Millisekunden (FLOWFACT) oder ISO-Text als TT.MM.JJJJ HH:MM Uhr.
     */
    public static function zeitpunktLesbar(string $wert): string
    {
        if (preg_match('/^\d{10,16}$/', $wert) === 1) {
            $sekunden = strlen($wert) > 11 ? intdiv((int) $wert, 1000) : (int) $wert;

            return Carbon::createFromTimestamp($sekunden, config('app.timezone', 'Europe/Berlin'))->format('d.m.Y H:i').' Uhr';
        }

        try {
            return Carbon::parse($wert)->setTimezone(config('app.timezone', 'Europe/Berlin'))->format('d.m.Y H:i').' Uhr';
        } catch (Throwable) {
            return $wert;
        }
    }

    private function lastModifiedNachlesen(string $schema, string $entityId, EntityService $entities): ?string
    {
        try {
            return EntityService::lastModified($entities->get($schema, $entityId));
        } catch (FlowfactException) {
            return null;
        }
    }

    /**
     * Zuletzt übertragene Version (link.release_id), ersatzweise die
     * Vorgängerversion der aktuellen Freigabe. Ist der Link bereits auf der
     * aktuellen Version (erneuter Lauf derselben Freigabe, z. B. nach einer
     * vorgemerkten Löschung), ist die aktuelle Version selbst der zuletzt
     * übertragene Stand; sonst würde eine ältere Version eine nie erfolgte
     * Drehung vortäuschen und Bilder löschen und erneut hochladen.
     */
    private function vorherigeVersion(Listing $listing, ListingFlowfactLink $link, ListingRelease $aktuell): ?ListingRelease
    {
        if ($link->release_id !== null) {
            if ((int) $link->release_id === (int) $aktuell->getKey()) {
                return $aktuell;
            }

            $release = ListingRelease::query()->whereKey($link->release_id)->first();

            if ($release !== null) {
                return $release;
            }
        }

        return $listing->releases()
            ->where('version', '<', $aktuell->version)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Löschliste für den PATCH (Masterprompt Abschnitt 23, Prüfbericht
     * 2026-09-12, Befund 5): Quelle ist die gespeicherte Zuordnung der
     * letzten Übertragung am Link (gesendete_felder_json), ersatzweise die an
     * der zuletzt übertragenen Version. Nur für Zeilen aus der Zeit vor dieser
     * Spalte wird die Vorgängerversion mit der aktuellen Zuordnung erneut
     * gemappt (dann nur Namen, keine Zuordnung).
     */
    private function mitLoeschungen(MappedPayload $payload, Listing $listing, ListingFlowfactLink $link, ListingRelease $aktuell): MappedPayload
    {
        if ($link->gesendeteFelder() !== null) {
            return $payload->loeschungenAus($link->gesendeteZuordnung(), $link->gesendeteFelder());
        }

        $vorherige = $this->vorherigeVersion($listing, $link, $aktuell);

        if ($vorherige === null) {
            return $payload->loeschungenAus([], []);
        }

        if ($vorherige->gesendeteFelder() !== null) {
            return $payload->loeschungenAus($vorherige->gesendeteZuordnung(), $vorherige->gesendeteFelder());
        }

        return $payload->loeschungenAus([], $this->mapper->mapSnapshot($vorherige->snapshot())->gesendeteFelder());
    }

    /**
     * Nach erfolgreichem Anlegen oder PATCH: gesendete Zielfeldnamen und
     * Entitätsstatus am Link merken (Befund 5 und 10), die Namen zusätzlich an
     * der übertragenen Version. Der Aufrufer speichert den Link.
     */
    private function merkeGesendet(ListingFlowfactLink $link, ListingRelease $release, MappedPayload $payload): void
    {
        // Gespeichert wird eigenes Feld => FLOWFACT-Feld; die Werte sind die
        // gesendeten Zielfeldnamen.
        $zuordnung = $payload->zuordnung;

        $link->gesendete_felder_json = $zuordnung;
        $link->flowfact_status = $payload->status();

        $release->gesendete_felder_json = $zuordnung;
        $release->saveQuietly();
    }

    /**
     * Prüfbericht 2026-09-12, Befund 10: ohne Veröffentlichungsrecht (oder
     * ohne handelnden Benutzer) wird eine neue Entität inaktiv angelegt; eine
     * zuvor inaktiv gesendete bleibt inaktiv. Eine bereits aktiv gesendete
     * oder in FLOWFACT vorgefundene Entität wird ohne Recht nicht
     * deaktiviert: das Abschalten ist ebenso eine Veröffentlichungshandlung
     * wie das Aktivieren und bleibt Berechtigten vorbehalten.
     */
    private function mitEntitaetsstatus(MappedPayload $payload, ?User $user, ListingFlowfactLink $link, bool $wirdAngelegt): MappedPayload
    {
        if ($user !== null && $user->kannVeroeffentlichen()) {
            return $payload;
        }

        if (! $wirdAngelegt && ! $link->inaktivGesendet()) {
            return $payload;
        }

        return $payload->mitStatus(ListingFlowfactLink::STATUS_INAKTIV, self::WARNUNG_INAKTIV_ANGELEGT);
    }

    /**
     * Ein Wechsel des gesendeten Entitätsstatus zählt als Inhaltsänderung,
     * damit eine inaktiv angelegte Entität vor der Veröffentlichung aktiviert
     * wird. Ältere Links ohne Wert wurden immer aktiv gesendet.
     */
    private function statusGeaendert(ListingFlowfactLink $link, MappedPayload $payload): bool
    {
        if ($link->flowfact_entity_id === null) {
            return false;
        }

        $gesendet = $link->flowfact_status ?? ListingFlowfactLink::STATUS_AKTIV;

        return $gesendet !== ($payload->status() ?? ListingFlowfactLink::STATUS_AKTIV);
    }

    /**
     * Schritt 4: gespeicherte ID prüfen, sonst Suche nach identifier. Liefert
     * die Entitäts-ID mit dem Änderungszeitpunkt aus _metadata.
     *
     * @return array{id: string|null, lastModified: string|null}|false ID (null: nicht vorhanden) oder false (Mehrfachtreffer)
     */
    private function findeEntitaet(Listing $listing, ListingFlowfactLink $link, string $schema, EntityService $entities, SearchService $search): array|false
    {
        // 4a: gespeicherte ID
        if ($link->flowfact_entity_id !== null) {
            try {
                $entity = $entities->get($schema, $link->flowfact_entity_id);

                return ['id' => $link->flowfact_entity_id, 'lastModified' => EntityService::lastModified($entity)];
            } catch (NotFoundException) {
                // ID verwerfen und über die Suche fortsetzen
                $link->flowfact_entity_id = null;
                $link->flowfact_last_modified = null;
                $link->save();
            }
        }

        // 4b: Suche nach identifier EQUALS objektnummer, exakte Nachprüfung
        $ergebnis = $search->findByField($schema, 'identifier', $listing->objektnummer, 2);
        $treffer = [];

        foreach ($ergebnis['entries'] as $eintrag) {
            $identifier = $eintrag['identifier']['values'][0] ?? null;

            if (is_scalar($identifier) && (string) $identifier === $listing->objektnummer && isset($eintrag['id']) && is_scalar($eintrag['id'])) {
                $treffer[(string) $eintrag['id']] = EntityService::lastModified($eintrag);
            }
        }

        if (count($treffer) > 1 || $ergebnis['totalCount'] > 2) {
            return false;
        }

        if (count($treffer) === 1) {
            $id = (string) array_key_first($treffer);
            $link->flowfact_entity_id = $id;
            $link->flowfact_schema = $schema;
            $link->save();

            return ['id' => $id, 'lastModified' => $treffer[$id]];
        }

        return ['id' => null, 'lastModified' => null];
    }

    private function linkFuer(Listing $listing): ListingFlowfactLink
    {
        $link = $listing->flowfactLink()->first();

        if ($link === null) {
            $link = $listing->flowfactLink()->create([
                'sync_status' => SyncStatus::NichtUebertragen,
            ]);
        }

        return $link;
    }

    private function fehlgeschlagen(ListingFlowfactLink $link, string $meldung, ?Throwable $ausnahme = null): SyncResult
    {
        $bereinigt = (string) $this->scrubber->scrub($meldung);

        $link->sync_status = SyncStatus::Fehlgeschlagen;
        $link->letzter_fehler = mb_substr($bereinigt, 0, 1000);
        $link->save();

        return SyncResult::failed($bereinigt, ausnahme: $ausnahme);
    }
}
