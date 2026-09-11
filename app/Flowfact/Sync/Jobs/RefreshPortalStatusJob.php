<?php

declare(strict_types=1);

namespace App\Flowfact\Sync\Jobs;

use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Client\Exceptions\FlowfactException;
use App\Flowfact\Client\Exceptions\RateLimitException;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Flowfact\Sync\PublishingService;
use App\Models\Listing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Liest den Portalstatus eines Objekts nach (docs/connector.md Abschnitt 5,
 * Punkt 3). Einzige Quelle für den Portalstatus "aktiv".
 */
final class RefreshPortalStatusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $listingId,
    ) {}

    public function handle(PublishingService $service): void
    {
        $listing = Listing::query()->find($this->listingId);

        if ($listing === null) {
            return;
        }

        if (! $service->isConfigured()) {
            $this->markiereUeberfaellige($listing);

            return;
        }

        try {
            $service->refreshStatus($listing);
        } catch (RateLimitException $exception) {
            $this->markiereUeberfaellige($listing);
            $this->release($exception->retryAfterSeconds);
        } catch (AuthenticationException $exception) {
            $this->markiereUeberfaellige($listing);
            Log::warning('Portalstatus konnte nicht gelesen werden: '.AuthenticationException::MELDUNG, ['listing_id' => $listing->id]);
        } catch (FlowfactException $exception) {
            $this->markiereUeberfaellige($listing);
            Log::warning('Portalstatus konnte nicht gelesen werden.', ['listing_id' => $listing->id, 'fehler' => $exception->getMessage()]);
        }
    }

    private function markiereUeberfaellige(Listing $listing): void
    {
        app(FlowfactPublishingService::class)->markiereUeberfaellige($listing);
    }
}
