<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Regression;

use App\Domain\Listing\ListingContentHasher;
use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Enums\SyncStatus;
use App\Flowfact\Sync\FlowfactPublishingService;
use App\Models\Listing;
use App\Models\ListingFlowfactLink;
use App\Models\ListingPortalPublication;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Flowfact\FlowfactTestCase;

/**
 * Masterprompt Abschnitt 24, Masterprompt-Abgleich B.6: Deaktivierung je
 * Portal mit den Status deaktivierung_angefordert und
 * deaktivierung_bestaetigt; ältere Zeilen mit zurueckgezogen bleiben lesbar
 * und gelten wie bestätigt.
 */
final class Regression11DeaktivierungTest extends FlowfactTestCase
{
    private const string PORTALS = '#^/portal-management-service/portals$#';

    private const string PUBLISH = '#^/portal-management-service/publish$#';

    private const string ESTATE_PORTALS = '#^/portal-management-service/estates/[^/]+/portals$#';

    protected function setUp(): void
    {
        parent::setUp();
        $this->hinterlegeToken();
        $this->setzeSchemata();
        Storage::fake('media');
    }

    private function veroeffentlichtesListing(): Listing
    {
        $listing = $this->bereitesListing(['status' => ListingStatus::Veroeffentlicht]);
        $listing->media()->update(['flowfact_multimedia_id' => '101', 'flowfact_titel' => 'Titelbild', 'titel' => 'Titelbild']);
        $listing = $listing->fresh(['price', 'energy', 'media']);
        $release = $this->freigeben($listing, ['portal-is24', 'portal-openimmo']);

        ListingFlowfactLink::factory()->create([
            'listing_id' => $listing->id,
            'flowfact_entity_id' => 'ent-1',
            'flowfact_schema' => self::SCHEMA_MIETE,
            'sync_status' => SyncStatus::Uebertragen,
            'uebertragener_inhalt_hash' => app(ListingContentHasher::class)->hash($listing),
            'release_id' => $release->id,
        ]);

        return $listing;
    }

    public function test_deaktivierung_je_portal_laesst_das_andere_portal_aktiv(): void
    {
        $listing = $this->veroeffentlichtesListing();

        foreach (['portal-is24' => 'ImmoScout24', 'portal-openimmo' => 'Immowelt (OpenImmo)'] as $id => $name) {
            ListingPortalPublication::factory()->create([
                'listing_id' => $listing->id,
                'portal_id' => $id,
                'portal_name' => $name,
                'status' => PortalStatus::Aktiv,
                'angefordert_at' => now()->subHours(2),
                'bestaetigt_at' => now()->subHour(),
            ]);
        }

        $online = ['portal-is24', 'portal-openimmo'];
        $fake = $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, fn () => Http::response('', 200))
            ->on('GET', self::ESTATE_PORTALS, function () use (&$online) {
                return Http::response(self::estatePortalsResponse($online));
            })
            ->install();
        $service = app(FlowfactPublishingService::class);

        $ergebnis = $service->withdraw($listing, ['portal-openimmo']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('POST', self::PUBLISH));
        self::assertSame('portal-openimmo', $fake->requests('POST', self::PUBLISH)[0]->data()['portalId']);
        self::assertSame(PortalStatus::DeaktivierungAngefordert, ListingPortalPublication::query()->where('portal_id', 'portal-openimmo')->first()->status);
        self::assertSame(PortalStatus::Aktiv, ListingPortalPublication::query()->where('portal_id', 'portal-is24')->first()->status);

        $online = ['portal-is24'];
        $service->refreshStatus($listing);

        self::assertSame(PortalStatus::DeaktivierungBestaetigt, ListingPortalPublication::query()->where('portal_id', 'portal-openimmo')->first()->status);
        self::assertSame(PortalStatus::Aktiv, ListingPortalPublication::query()->where('portal_id', 'portal-is24')->first()->status);
        self::assertSame(ListingStatus::Veroeffentlicht, $listing->fresh()->status, 'Ein aktives Portal hält das Objekt veröffentlicht.');
    }

    public function test_aeltere_zurueckgezogene_zeile_gilt_wie_bestaetigte_deaktivierung(): void
    {
        $listing = $this->veroeffentlichtesListing();
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-is24',
            'status' => PortalStatus::Zurueckgezogen,
            'angefordert_at' => now()->subHours(3),
            'bestaetigt_at' => now()->subHours(2),
            'zurueckgezogen_at' => now()->subHour(),
        ]);
        Http::fake([self::BASE.'/portal-management-service/estates/*' => Http::response([])]);

        app(FlowfactPublishingService::class)->refreshStatus($listing);

        self::assertSame(PortalStatus::Zurueckgezogen, ListingPortalPublication::query()->first()->status, 'Alte Zeilen werden nicht umgeschrieben.');
        self::assertSame(ListingStatus::Zurueckgezogen, $listing->fresh()->status);
    }

    public function test_manuell_freizugebende_publikation_kann_deaktiviert_werden(): void
    {
        $listing = $this->veroeffentlichtesListing();
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-is24',
            'status' => PortalStatus::ManuelleFreigabeErforderlich,
            'angefordert_at' => now()->subHour(),
            'letzter_fehler' => FlowfactPublishingService::MELDUNG_MANUELLE_FREIGABE,
        ]);
        $fake = $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, fn () => Http::response('', 200))
            ->on('GET', self::ESTATE_PORTALS, fn () => Http::response([]))
            ->install();

        $ergebnis = app(FlowfactPublishingService::class)->withdraw($listing, ['portal-is24']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertSame(1, $fake->count('POST', self::PUBLISH));
        // Nie online, kein Eintrag: bestätigt deaktiviert; Objekt zurück nach bereit.
        self::assertSame(PortalStatus::DeaktivierungBestaetigt, ListingPortalPublication::query()->first()->status);
        self::assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    public function test_403_bei_der_deaktivierung_laesst_die_anforderung_mit_hinweis_stehen(): void
    {
        $listing = $this->veroeffentlichtesListing();
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-is24',
            'status' => PortalStatus::Aktiv,
            'angefordert_at' => now()->subHours(2),
            'bestaetigt_at' => now()->subHour(),
        ]);
        $this->fake()
            ->on('GET', self::PORTALS, self::portalsResponse())
            ->on('POST', self::PUBLISH, ['message' => 'forbidden'], 403)
            ->install();

        $ergebnis = app(FlowfactPublishingService::class)->withdraw($listing, ['portal-is24']);

        self::assertTrue($ergebnis->ok, $ergebnis->meldung);
        self::assertContains(FlowfactPublishingService::MELDUNG_MANUELLE_DEAKTIVIERUNG, $ergebnis->warnungen);
        $publication = ListingPortalPublication::query()->first();
        self::assertSame(PortalStatus::DeaktivierungAngefordert, $publication->status);
        self::assertSame(FlowfactPublishingService::MELDUNG_MANUELLE_DEAKTIVIERUNG, $publication->letzter_fehler);
    }

    public function test_portal_status_befehl_prueft_angeforderte_deaktivierungen(): void
    {
        $listing = $this->veroeffentlichtesListing();
        ListingPortalPublication::factory()->create([
            'listing_id' => $listing->id,
            'portal_id' => 'portal-is24',
            'status' => PortalStatus::DeaktivierungAngefordert,
            'angefordert_at' => now()->subHours(2),
            'bestaetigt_at' => now()->subHour(),
            'zurueckgezogen_at' => now()->subMinutes(3),
        ]);
        Http::fake([self::BASE.'/portal-management-service/estates/*' => Http::response([])]);

        $this->artisan('flow:portal-status')->assertExitCode(0);

        self::assertSame(PortalStatus::DeaktivierungBestaetigt, ListingPortalPublication::query()->first()->status);
        self::assertSame(ListingStatus::Zurueckgezogen, $listing->fresh()->status);
    }
}
