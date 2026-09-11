<?php

namespace App\Providers;

use App\Flowfact\Sync\NullPublishingService;
use App\Flowfact\Sync\PublishingService;
use App\Services\Ai\FakeTextGenerator;
use App\Services\Ai\TextGenerator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Schnittstellen der Oberfläche zu Connector und KI-Texten. Die echten
        // Umsetzungen ersetzen diese Bindungen, sobald sie konfiguriert sind.
        $this->app->bind(PublishingService::class, NullPublishingService::class);
        $this->app->bind(TextGenerator::class, FakeTextGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
