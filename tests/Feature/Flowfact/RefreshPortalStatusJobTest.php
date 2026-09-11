<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Flowfact\Sync\Jobs\RefreshPortalStatusJob;
use App\Flowfact\Sync\PublishingService;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\ListingPortalPublication;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

final class RefreshPortalStatusJobTest extends FlowfactTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->hinterlegeToken();
        Carbon::setTestNow('2026-09-11 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function listingMitPublikation(PortalStatus $status, Carbon $angefordertAt, ?Carbon $letztePruefung = null, string $portal = 'portal-is24'): Listing
    {
        $listing = Listing::factory()->miete()->vollstaendig()->create(['status' => ListingStatus::Veroeffentlicht]);
        ListingFlowfactLink::factory()->create(['listing_id' => $listing->id, 'flowfact_entity_id' => 'ent-1', 'flowfact_schema' => self::SCHEMA_MIETE]);
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => $portal,
            'status' => $status,
            'angefordert_at' => $angefordertAt,
            'letzte_pruefung_at' => $letztePruefung,
        ]);

        return $listing;
    }

    public function test_aktiv_nach_ruecklesen_mit_online_since(): void
    {
        $listing = $this->listingMitPublikation(PortalStatus::Angefordert, Carbon::now()->subMinutes(5));
        Http::fake([self::BASE.'/portal-management-service/estates/ent-1/portals' => Http::response(self::estatePortalsResponse(['portal-is24']))]);

        (new RefreshPortalStatusJob($listing->id))->handle(app(PublishingService::class));

        $publication = ListingPortalPublication::query()->first();
        self::assertSame(PortalStatus::Aktiv, $publication->status);
        self::assertTrue($publication->bestaetigt_at->equalTo(Carbon::now()));
        self::assertTrue($publication->letzte_pruefung_at->equalTo(Carbon::now()));
    }

    public function test_unbekannt_nach_30_minuten_ohne_eintrag(): void
    {
        $listing = $this->listingMitPublikation(PortalStatus::Angefordert, Carbon::now()->subMinutes(30));
        Http::fake([self::BASE.'/portal-management-service/estates/ent-1/portals' => Http::response([])]);

        (new RefreshPortalStatusJob($listing->id))->handle(app(PublishingService::class));

        $publication = ListingPortalPublication::query()->first();
        self::assertSame(PortalStatus::Unbekannt, $publication->status);
        self::assertSame(FlowfactPublishingService::HINWEIS_UNBEKANNT, $publication->letzter_fehler);
    }

    public function test_junge_anforderung_bleibt_angefordert(): void
    {
        $listing = $this->listingMitPublikation(PortalStatus::Angefordert, Carbon::now()->subMinutes(10));
        Http::fake([self::BASE.'/portal-management-service/estates/ent-1/portals' => Http::response([])]);

        (new RefreshPortalStatusJob($listing->id))->handle(app(PublishingService::class));

        self::assertSame(PortalStatus::Angefordert, ListingPortalPublication::query()->first()->status);
    }

    public function test_bei_lesefehler_wird_die_ueberfaellige_anforderung_trotzdem_unbekannt(): void
    {
        $listing = $this->listingMitPublikation(PortalStatus::Angefordert, Carbon::now()->subMinutes(45));
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'down'], 500)]);

        (new RefreshPortalStatusJob($listing->id))->handle(app(PublishingService::class));

        self::assertSame(PortalStatus::Unbekannt, ListingPortalPublication::query()->first()->status);
    }

    public function test_command_prueft_angeforderte_immer_und_aktive_nur_nach_60_minuten(): void
    {
        $angefordert = $this->listingMitPublikation(PortalStatus::Angefordert, Carbon::now()->subMinutes(2));
        $aktivFrisch = $this->listingMitPublikation(PortalStatus::Aktiv, Carbon::now()->subHours(3), Carbon::now()->subMinutes(20));
        $aktivAlt = $this->listingMitPublikation(PortalStatus::Aktiv, Carbon::now()->subHours(3), Carbon::now()->subMinutes(61));
        $aktivNie = $this->listingMitPublikation(PortalStatus::Aktiv, Carbon::now()->subHours(3), null);
        $zurueckgezogen = $this->listingMitPublikation(PortalStatus::Zurueckgezogen, Carbon::now()->subHours(3));

        $gepruefteEntities = [];
        Http::fake(function ($request) use (&$gepruefteEntities) {
            $gepruefteEntities[] = $request->url();

            return Http::response(self::estatePortalsResponse(['portal-is24']));
        });

        $this->artisan('flow:portal-status')->assertExitCode(0);

        // Prüfbericht 2026-09-11, Befund 1: ein veröffentlichtes Objekt ohne offene
        // Publikation (nur zurueckgezogen) wird ebenfalls geprüft, damit das
        // Rücklesen den Objektstatus zurückführt. Hier meldet FLOWFACT es online,
        // die Publikation wird wieder aktiv und das Objekt bleibt veröffentlicht.
        self::assertCount(4, $gepruefteEntities);
        self::assertSame(PortalStatus::Aktiv, $zurueckgezogen->portalPublications()->first()->status);
        self::assertSame(ListingStatus::Veroeffentlicht, $zurueckgezogen->fresh()->status);
        self::assertTrue($aktivFrisch->portalPublications()->first()->letzte_pruefung_at->equalTo(Carbon::now()->subMinutes(20)));
        self::assertTrue($aktivAlt->portalPublications()->first()->letzte_pruefung_at->equalTo(Carbon::now()));
        self::assertTrue($aktivNie->portalPublications()->first()->letzte_pruefung_at->equalTo(Carbon::now()));
        self::assertSame(PortalStatus::Aktiv, $angefordert->portalPublications()->first()->status);
    }

    public function test_command_ohne_token_markiert_nur_ueberfaellige(): void
    {
        $this->settings()->forget('flowfact.api_token');
        $listing = $this->listingMitPublikation(PortalStatus::Angefordert, Carbon::now()->subMinutes(40));
        Http::fake();

        $this->artisan('flow:portal-status')->assertExitCode(0);

        Http::assertNothingSent();
        self::assertSame(PortalStatus::Unbekannt, $listing->portalPublications()->first()->status);
    }

    public function test_command_ohne_offene_publikationen_endet_sauber(): void
    {
        Http::fake();

        $this->artisan('flow:portal-status')->expectsOutputToContain('Keine Objekte zu prüfen.')->assertExitCode(0);
    }

    public function test_scheduler_kennt_den_portalstatus_alle_fuenf_minuten(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'flow:portal-status'));

        self::assertCount(1, $events);
        self::assertSame('*/5 * * * *', $events->first()->expression);
        self::assertTrue($events->first()->withoutOverlapping);
    }
}
