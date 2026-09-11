<?php

declare(strict_types=1);

namespace App\Flowfact\Sync\Jobs;

use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Client\Exceptions\RateLimitException;
use App\Flowfact\Client\Exceptions\ServerException;
use App\Flowfact\Client\Exceptions\TransportException;
use App\Flowfact\Sync\ListingSyncService;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Übertragung als Job (docs/connector.md Abschnitt 3, Schritt 9).
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

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $listingId,
        public readonly ?int $userId = null,
        public readonly bool $force = false,
    ) {}

    public function handle(ListingSyncService $sync): void
    {
        $listing = Listing::query()->find($this->listingId);

        if ($listing === null) {
            return;
        }

        $user = $this->userId !== null ? User::query()->find($this->userId) : null;
        $ergebnis = $sync->sync($listing, $user, $this->force, self::ZEITLIMIT_SEKUNDEN);

        if ($ergebnis->ok) {
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
}
