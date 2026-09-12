<?php

declare(strict_types=1);

namespace App\Flowfact\Sync\Jobs;

use App\Enums\SyncStatus;
use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Client\Exceptions\RateLimitException;
use App\Flowfact\Client\Exceptions\ServerException;
use App\Flowfact\Client\Exceptions\TransportException;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Flowfact\Sync\ListingSyncService;
use App\Flowfact\Sync\ReleaseGuard;
use App\Models\Listing;
use App\Models\ListingRelease;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Übertragung als Job (docs/connector.md Abschnitt 3, Schritt 9; Masterprompt
 * Abschnitt 19 und 24).
 *
 * Der Job trägt die release_id, mit der er eingereiht wurde. Vor jeder
 * Handlung prüft er über ReleaseGuard: Ist die Version noch die jüngste
 * (sonst "Veraltete Freigabe übersprungen" im Übertragungsprotokoll)? Ist
 * das Objekt nicht archiviert? Soll anschließend veröffentlicht werden
 * (veroeffentlichen = true, Fortsetzung nach Zeitlimit beim Bildupload),
 * prüft er zusätzlich, ob nach der Freigabe eine Deaktivierung angefordert
 * wurde; dann fordert er keine Veröffentlichung mehr an.
 *
 * Server- und Transportfehler: bis zu drei Versuche mit Backoff 30, 120,
 * 300 Sekunden. Ratenbegrenzung: neu einreihen nach Retry-After.
 * Auth-Fehler: sofort endgültig fehlgeschlagen, eine Wiederholung ändert
 * nichts. Der Ablauf im Service ist idempotent, ein erneuter Versuch legt
 * keine zweite Entität an.
 *
 * Zeitlimit (Prüfbericht 2026-09-11, Befund 7): 150 Sekunden, unterhalb der
 * Lease von 3 Minuten. Bleiben Bilder offen, reiht der Service den Rest
 * selbst als neuen Job ein, wie im synchronen Weg.
 */
final class TransferListingJob implements ShouldQueue
{
    use Queueable;

    public const int ZEITLIMIT_SEKUNDEN = 150;

    public const string AKTION = 'job TransferListingJob';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $listingId,
        public readonly ?int $userId = null,
        public readonly bool $force = false,
        public readonly ?int $releaseId = null,
        public readonly bool $veroeffentlichen = false,
    ) {}

    public function handle(ListingSyncService $sync, ?ReleaseGuard $guard = null): void
    {
        $listing = Listing::query()->find($this->listingId);

        if ($listing === null) {
            return;
        }

        $guard ??= app(ReleaseGuard::class);

        if ($guard->pruefeJob($listing, $this->releaseId, self::AKTION) !== null) {
            return;
        }

        $user = $this->userId !== null ? User::query()->find($this->userId) : null;
        $ergebnis = $sync->sync($listing, $user, $this->force, self::ZEITLIMIT_SEKUNDEN);

        if ($ergebnis->ok) {
            $this->veroeffentlicheFallsFreigegeben($listing, $user, $guard);

            return;
        }

        if ($ergebnis->busy) {
            $this->release(60);

            return;
        }

        $ausnahme = $ergebnis->ausnahme;

        if ($ausnahme instanceof RateLimitException) {
            $this->release($ausnahme->retryAfterSeconds);

            return;
        }

        if ($ausnahme instanceof AuthenticationException) {
            $this->fail(new RuntimeException(AuthenticationException::MELDUNG.'. Der Job wird nicht wiederholt, bitte Token im Adminbereich prüfen.'));

            return;
        }

        if ($ausnahme instanceof ServerException || $ausnahme instanceof TransportException) {
            throw $ausnahme;
        }

        $this->fail(new RuntimeException($ergebnis->meldung));
    }

    /**
     * Fortsetzung einer Veröffentlichung nach dem Zeitlimit beim Bildupload
     * (Befund 15, Masterprompt 19): erst wenn die Übertragung vollständig ist,
     * die Freigabe noch die jüngste ist und keine Deaktivierung nach der
     * Freigabe angefordert wurde, wird POST /publish ONLINE ausgelöst.
     */
    private function veroeffentlicheFallsFreigegeben(Listing $listing, ?User $user, ReleaseGuard $guard): void
    {
        if (! $this->veroeffentlichen || $this->releaseId === null) {
            return;
        }

        $link = $listing->flowfactLink()->first();

        if ($link === null || $link->sync_status !== SyncStatus::Uebertragen) {
            // Noch nicht vollständig: der Service hat einen weiteren Job eingereiht.
            return;
        }

        $listing->refresh();

        if ($guard->pruefeJob($listing, $this->releaseId, self::AKTION.' publish') !== null) {
            return;
        }

        /** @var ListingRelease|null $release */
        $release = ListingRelease::query()->find($this->releaseId);

        if ($release === null || $release->portalIds() === []) {
            return;
        }

        if ($guard->deaktivierungNachFreigabe($listing, $release)) {
            $guard->protokolliere($listing, self::AKTION.' publish', ReleaseGuard::MELDUNG_DEAKTIVIERUNG, $this->releaseId);

            return;
        }

        app(FlowfactPublishingService::class)->publish($listing, $release->portalIds(), $user);
    }
}
