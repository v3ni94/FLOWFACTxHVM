<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Flowfact\Sync\FlowfactPublishingService;
use App\Flowfact\Sync\NullPublishingService;
use App\Flowfact\Sync\PublishingService;
use App\Models\Listing;
use Illuminate\Support\Facades\Http;

final class PublishingServiceBindingTest extends FlowfactTestCase
{
    public function test_ohne_token_ist_der_null_service_gebunden(): void
    {
        Http::fake();

        $service = app(PublishingService::class);

        self::assertInstanceOf(NullPublishingService::class, $service);
        self::assertFalse($service->isConfigured());
        self::assertFalse($service->transfer(Listing::factory()->create())->ok);
        Http::assertNothingSent();
    }

    public function test_die_bindung_wird_je_aufloesung_neu_bewertet(): void
    {
        self::assertInstanceOf(NullPublishingService::class, app(PublishingService::class));

        $this->hinterlegeToken();
        self::assertInstanceOf(FlowfactPublishingService::class, app(PublishingService::class));

        $this->settings()->forget('flowfact.api_token');
        self::assertInstanceOf(NullPublishingService::class, app(PublishingService::class));
    }
}
