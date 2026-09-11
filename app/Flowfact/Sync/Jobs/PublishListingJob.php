<?php

declare(strict_types=1);

namespace App\Flowfact\Sync\Jobs;

use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Client\Exceptions\RateLimitException;
use App\Flowfact\Client\Exceptions\ServerException;
use App\Flowfact\Client\Exceptions\TransportException;
use App\Flowfact\Sync\PublishingService;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Veröffentlichung als Job mit derselben Wiederholungslogik wie
 * TransferListingJob. Setzt selbst nie "aktiv".
 */
final class PublishListingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  list<string>  $portalIds
     */
    public function __construct(
        public readonly int $listingId,
        public readonly array $portalIds,
        public readonly ?int $userId = null,
    ) {}

    public function handle(PublishingService $service): void
    {
        $listing = Listing::query()->find($this->listingId);

        if ($listing === null) {
            return;
        }

        $user = $this->userId !== null ? User::query()->find($this->userId) : null;
        $ergebnis = $service->publish($listing, $this->portalIds, $user);

        if ($ergebnis->ok) {
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
