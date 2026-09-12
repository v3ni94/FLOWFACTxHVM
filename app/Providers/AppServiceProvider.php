<?php

namespace App\Providers;

use App\Domain\Settings\SettingsRepository;
use App\Flowfact\Client\SettingsTokenProvider;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Flowfact\Sync\NullPublishingService;
use App\Flowfact\Sync\PublishingService;
use App\Models\Listing;
use App\Models\ListingEnergy;
use App\Models\ListingInternal;
use App\Models\ListingMedia;
use App\Models\ListingPrice;
use App\Observers\ListingChangeObserver;
use App\Services\Ai\AnthropicTextGenerator;
use App\Services\Ai\FakeTextGenerator;
use App\Services\Ai\FakeTextReviser;
use App\Services\Ai\TextGenerator;
use App\Services\Ai\TextReviser;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Schnittstelle der Oberfläche zum FLOWFACT-Connector (docs/connector.md
        // Abschnitt 1): die echte Umsetzung, sobald ein Token hinterlegt ist,
        // sonst NullPublishingService. Bewusst je Auflösung geprüft, damit ein
        // im laufenden Betrieb hinterlegter oder entfernter Token sofort wirkt.
        $this->app->bind(PublishingService::class, function ($app): PublishingService {
            return $app->make(SettingsRepository::class)->hasSecret(SettingsTokenProvider::TOKEN_KEY)
                ? $app->make(FlowfactPublishingService::class)
                : $app->make(NullPublishingService::class);
        });
        // KI-Textvorschläge (ADR-009): der echte Anbieter wird nur genutzt,
        // wenn er als aktiv ausgewählt ist UND ein Schlüssel vorliegt. Je
        // Auflösung geprüft, damit eine im laufenden Betrieb geänderte
        // Einstellung sofort wirkt. In Tests bleibt der Provider "fake"
        // (phpunit.xml, AI_PROVIDER), sodass hier nie ein echter Aufruf
        // entsteht.
        $this->app->bind(TextGenerator::class, function ($app): TextGenerator {
            $anthropic = $app->make(AnthropicTextGenerator::class);

            return $anthropic->isConfigured() ? $anthropic : $app->make(FakeTextGenerator::class);
        });

        // Überarbeitung vorhandener Texte (Masterprompt Abschnitt 16): kürzer,
        // sachlicher, sprachlich verbessern. Eigenständig gebunden, unabhängig
        // davon, ob TextGenerator zuvor aufgelöst wurde. Die KI-Umsetzung folgt
        // in Welle 3, bis dahin bleibt der regelbasierte FakeTextReviser aktiv.
        $this->app->bind(TextReviser::class, FakeTextReviser::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Änderungshistorie je Feld (Masterprompt-Abgleich B.6). Interne Felder
        // werden protokolliert, aber nie exportiert.
        Listing::observe(ListingChangeObserver::class);
        ListingPrice::observe(ListingChangeObserver::class);
        ListingEnergy::observe(ListingChangeObserver::class);
        ListingInternal::observe(ListingChangeObserver::class);
        ListingMedia::observe(ListingChangeObserver::class);
    }
}
