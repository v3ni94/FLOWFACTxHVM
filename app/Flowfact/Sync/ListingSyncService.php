<?php

declare(strict_types=1);

namespace App\Flowfact\Sync;

use App\Domain\Listing\CompletenessCheck;
use App\Domain\Listing\ListingContentHasher;
use App\Domain\Settings\SettingsRepository;
use App\Enums\ListingStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Client\Exceptions\FlowfactException;
use App\Flowfact\Client\Exceptions\NotFoundException;
use App\Flowfact\Client\TokenScrubber;
use App\Flowfact\Mapping\FlowfactPayloadMapper;
use App\Flowfact\Services\EntityService;
use App\Flowfact\Services\SearchService;
use App\Flowfact\Sync\Jobs\TransferListingJob;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Ablauf einer Übertragung (docs/connector.md Abschnitt 3), idempotent
 * (Datenvertrag Grundsatz 4, ADR-005).
 *
 * WARUM Suche vor Anlegen: Nach einer Zeitüberschreitung eines POST ist
 * unbekannt, ob die Entität existiert. Der nächste Lauf sucht deshalb zuerst
 * über die Objektnummer (identifier). Ein zweites Anlegen ist damit
 * ausgeschlossen, solange die Suche funktioniert; liefert die Suche einen
 * Fehler statt eines leeren Ergebnisses, wird nicht angelegt.
 *
 * Nach Prüfbericht 2026-09-11:
 * - Befund 3: Ist eine Entität bekannt, gilt das im Link gespeicherte Schema.
 *   Ein Wechsel der Vermarktungsart nach der Übertragung wird abgelehnt statt
 *   eine zweite Entität im anderen Schema anzulegen. Jede Übertragung setzt
 *   die bestandene Vollständigkeitsprüfung voraus.
 * - Befund 5: Beim PATCH werden geleerte, zugeordnete Felder als
 *   { "values": [] } gesendet (Einstellung flowfact.leere_felder_loeschen).
 * - Befund 7: Lease mit Token, Freigabe nur der eigenen Lease, Herzschlag
 *   nach jedem Bildupload.
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

    /**
     * Einstellung (Standard true): geleerte Felder beim PATCH mit leerer
     * Werteliste senden, damit FLOWFACT den alten Wert löscht. Die genaue
     * Serversemantik ist am Konto zu verifizieren (flowfact-api.md Abschnitt 9).
     */
    public const string LEERE_FELDER_LOESCHEN = 'flowfact.leere_felder_loeschen';

    public function __construct(
        private readonly EntityService $entities,
        private readonly SearchService $search,
        private readonly MediaSyncService $media,
        private readonly FlowfactPayloadMapper $mapper,
        private readonly ListingContentHasher $hasher,
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

        $listing->loadMissing(['price', 'energy', 'media']);
        $link = $this->linkFuer($listing);

        // Vollständigkeit in jedem Status (Befund 3): ein unvollständiges
        // Objekt geht nie an FLOWFACT, auch nicht als Aktualisierung.
        $vollstaendigkeit = $this->completeness->check($listing);

        if (! $vollstaendigkeit->istVollstaendig()) {
            return $this->fehlgeschlagen($link, sprintf(self::MELDUNG_UNVOLLSTAENDIG, implode(', ', $vollstaendigkeit->fehlend)));
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
            $abgeleitet = $this->schemaFuer($listing);

            if ($abgeleitet === null) {
                return $this->fehlgeschlagen($link, sprintf(self::MELDUNG_SCHEMA_FEHLT, $listing->istMiete() ? 'Miete' : 'Kauf'));
            }

            $schema = $this->gespeichertesSchema($link) ?? $abgeleitet;

            if ($schema !== $abgeleitet) {
                return $this->fehlgeschlagen($link, self::MELDUNG_SCHEMAWECHSEL);
            }

            $entities = $this->entities->scoped($listing, $user);
            $search = $this->search->scoped($listing, $user);

            // Schritt 4: Entität finden
            $entityId = $this->findeEntitaet($listing, $link, $schema, $entities, $search);

            if ($entityId === false) {
                return $this->fehlgeschlagen($link, self::MELDUNG_MEHRFACHTREFFER);
            }

            // Schritt 5: Payload und Hash
            $payload = $this->mapper->map($listing);
            $hash = $this->hasher->hash($listing);
            $warnungen = $payload->warnungen;

            // Schritt 6: Anlegen oder Aktualisieren
            $angelegt = false;

            if ($entityId === null) {
                $entityId = $entities->create($schema, $payload->fields);

                // ID sofort speichern, bevor irgendetwas anderes passiert.
                $link->flowfact_entity_id = $entityId;
                $link->flowfact_schema = $schema;
                $link->save();
                $angelegt = true;
            }

            $inhaltGeaendert = $angelegt || $force || $link->uebertragener_inhalt_hash !== $hash;

            if (! $angelegt && $inhaltGeaendert) {
                $entities->patch($schema, $entityId, $this->leereFelderLoeschen() ? $payload->fieldsMitLoeschungen() : $payload->fields);
            }

            // Schritt 7: Medien
            $medienVollstaendig = true;

            if ($inhaltGeaendert || $this->media->hatOffeneArbeit($listing)) {
                $heartbeat = function () use ($link, $token): void {
                    $this->lease->extend($link, $token);
                };

                $medien = $this->media->sync($listing, $schema, $entityId, $user, $deadline, $inhaltGeaendert, $heartbeat);
                $warnungen = array_merge($warnungen, $medien->warnungen);
                $medienVollstaendig = $medien->vollstaendig;
            }

            // Schritt 8: Abschluss
            $link->letzte_uebertragung_at = Carbon::now();
            $link->letzter_fehler = null;

            if ($medienVollstaendig) {
                $link->uebertragener_inhalt_hash = $hash;
                $link->sync_status = SyncStatus::Uebertragen;
            } else {
                // Hash bewusst nicht setzen: der Job soll den Rest übertragen.
                $link->sync_status = SyncStatus::GeaendertSeitUebertragung;
                $warnungen[] = 'Das Zeitlimit wurde erreicht, die restlichen Bilder werden im Hintergrund übertragen.';
            }

            $link->save();

            if (! $medienVollstaendig) {
                TransferListingJob::dispatch($listing->id, $user?->id, false)->afterCommit();
            }

            $meldung = match (true) {
                $angelegt => 'Objekt in FLOWFACT angelegt.',
                $inhaltGeaendert => 'Objekt in FLOWFACT aktualisiert.',
                default => 'Keine Änderungen seit der letzten Übertragung.',
            };

            return new SyncResult(true, $meldung, $entityId, array_values(array_unique($warnungen)));
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

    public function schemaFuer(Listing $listing): ?string
    {
        $schema = $this->settings->get($listing->istMiete() ? self::SCHEMA_MIETE : self::SCHEMA_KAUF);

        return is_string($schema) && trim($schema) !== '' ? trim($schema) : null;
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

    private function leereFelderLoeschen(): bool
    {
        $wert = $this->settings->get(self::LEERE_FELDER_LOESCHEN, true);

        return filter_var($wert, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /**
     * Schritt 4: gespeicherte ID prüfen, sonst Suche nach identifier.
     *
     * @return string|null|false ID, null (nicht vorhanden) oder false (Mehrfachtreffer)
     */
    private function findeEntitaet(Listing $listing, ListingFlowfactLink $link, string $schema, EntityService $entities, SearchService $search): string|null|false
    {
        // 4a: gespeicherte ID
        if ($link->flowfact_entity_id !== null) {
            try {
                $entities->get($schema, $link->flowfact_entity_id);

                return $link->flowfact_entity_id;
            } catch (NotFoundException) {
                // ID verwerfen und über die Suche fortsetzen
                $link->flowfact_entity_id = null;
                $link->save();
            }
        }

        // 4b: Suche nach identifier EQUALS objektnummer, exakte Nachprüfung
        $ergebnis = $search->findByField($schema, 'identifier', $listing->objektnummer, 2);
        $treffer = [];

        foreach ($ergebnis['entries'] as $eintrag) {
            $identifier = $eintrag['identifier']['values'][0] ?? null;

            if (is_scalar($identifier) && (string) $identifier === $listing->objektnummer && isset($eintrag['id']) && is_scalar($eintrag['id'])) {
                $treffer[] = (string) $eintrag['id'];
            }
        }

        $treffer = array_values(array_unique($treffer));

        if (count($treffer) > 1 || $ergebnis['totalCount'] > 2) {
            return false;
        }

        if (count($treffer) === 1) {
            $link->flowfact_entity_id = $treffer[0];
            $link->flowfact_schema = $schema;
            $link->save();

            return $treffer[0];
        }

        return null;
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
