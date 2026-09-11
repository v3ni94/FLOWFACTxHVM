<?php

declare(strict_types=1);

namespace App\Flowfact;

use App\Flowfact\Client\FlowfactClient;
use App\Flowfact\Client\SettingsTokenProvider;
use App\Flowfact\Client\TokenProvider;
use App\Flowfact\Client\TokenScrubber;
use App\Flowfact\Client\TransferLogRecorder;
use App\Flowfact\Services\ImageResizer;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Flowfact\Sync\ListingMediaObserver;
use App\Flowfact\Sync\SyncLease;
use App\Models\ListingMedia;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Bindungen des FLOWFACT-Connectors (docs/connector.md Abschnitt 1).
 *
 * Die Bindung des Interfaces PublishingService bleibt in AppServiceProvider,
 * weil sie dort für die Oberfläche dokumentiert ist.
 */
final class FlowfactServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TokenProvider::class, SettingsTokenProvider::class);

        $this->app->bind(TransferLogRecorder::class, fn ($app): TransferLogRecorder => new TransferLogRecorder(
            $app->make(TokenScrubber::class),
            (int) config('flowfact.log_body_limit', 4096),
        ));

        $this->app->bind(FlowfactClient::class, fn ($app): FlowfactClient => new FlowfactClient(
            $app->make(HttpFactory::class),
            $app->make(TokenProvider::class),
            $app->make(TransferLogRecorder::class),
            $app->make(TokenScrubber::class),
            (string) config('flowfact.base_url'),
            (int) config('flowfact.timeout', 20),
            (int) config('flowfact.upload_timeout', 60),
        ));

        $this->app->bind(ImageResizer::class, fn (): ImageResizer => new ImageResizer(
            (int) config('flowfact.image.max_side', 2000),
            (int) config('flowfact.image.jpeg_quality', 85),
        ));

        $this->app->bind(SyncLease::class, fn (): SyncLease => new SyncLease((int) config('flowfact.lease_minutes', 3)));

        $this->app->when(FlowfactPublishingService::class)
            ->needs('$syncZeitlimitSekunden')
            ->give(fn (): int => (int) config('flowfact.sync_time_limit_seconds', 25));

        $this->app->when(FlowfactPublishingService::class)
            ->needs('$unbekanntNachMinuten')
            ->give(fn (): int => (int) config('flowfact.portal_status.angefordert_unbekannt_nach_minuten', 30));
    }

    public function boot(): void
    {
        ListingMedia::observe(ListingMediaObserver::class);
    }
}
