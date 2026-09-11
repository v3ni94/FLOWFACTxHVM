<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Flowfact\Sync\PublishingService;
use App\Flowfact\Sync\PublishResult;
use App\Flowfact\Sync\SyncResult;
use App\Models\Listing;
use App\Models\ListingPortalPublication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prüfbericht 2026-09-11, Befund 1 (Controller-Teil): Der Rückzug fand bisher
 * nur Publikationen im Status "angefordert" oder "aktiv". Ein Objekt, dessen
 * Portalanfragen alle mit "fehler" oder "unbekannt" endeten, hatte darüber
 * keinen Ausweg über die Oberfläche mehr (Statuswechsel nach
 * "veroeffentlicht" bleibt bestehen, siehe Befund 1 im Prüfbericht). Der
 * Controller muss beide zusätzlichen Status an PublishingService::withdraw
 * übergeben.
 */
final class ListingWithdrawControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_der_rueckzug_beruecksichtigt_auch_fehler_und_unbekannt(): void
    {
        $fake = new class implements PublishingService
        {
            /** @var list<string> */
            public array $angefragtePortalIds = [];

            public function isConfigured(): bool
            {
                return true;
            }

            public function portals(): array
            {
                return [];
            }

            public function transfer(Listing $listing, ?User $user = null): SyncResult
            {
                return SyncResult::failed('sollte nicht aufgerufen werden');
            }

            public function publish(Listing $listing, array $portalIds, ?User $user = null): PublishResult
            {
                return new PublishResult(true, 'sollte nicht aufgerufen werden');
            }

            public function withdraw(Listing $listing, array $portalIds, ?User $user = null): PublishResult
            {
                $this->angefragtePortalIds = $portalIds;

                return new PublishResult(true, 'Der Rückzug wurde angefordert.');
            }

            public function refreshStatus(Listing $listing): void {}
        };

        $this->app->instance(PublishingService::class, $fake);

        $user = User::factory()->create();
        $listing = Listing::factory()->create(['status' => ListingStatus::Veroeffentlicht]);

        ListingPortalPublication::factory()->for($listing)->create(['portal_id' => 'portal-angefordert', 'status' => PortalStatus::Angefordert]);
        ListingPortalPublication::factory()->for($listing)->create(['portal_id' => 'portal-aktiv', 'status' => PortalStatus::Aktiv]);
        ListingPortalPublication::factory()->for($listing)->create(['portal_id' => 'portal-fehler', 'status' => PortalStatus::Fehler]);
        ListingPortalPublication::factory()->for($listing)->create(['portal_id' => 'portal-unbekannt', 'status' => PortalStatus::Unbekannt]);
        ListingPortalPublication::factory()->for($listing)->create(['portal_id' => 'portal-zurueckgezogen', 'status' => PortalStatus::Zurueckgezogen]);
        ListingPortalPublication::factory()->for($listing)->create(['portal_id' => 'portal-nicht-veroeffentlicht', 'status' => PortalStatus::NichtVeroeffentlicht]);

        $response = $this->actingAs($user)->post(route('app.listings.withdraw', $listing));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Der Rückzug wurde angefordert.');

        self::assertEqualsCanonicalizing(
            ['portal-angefordert', 'portal-aktiv', 'portal-fehler', 'portal-unbekannt'],
            $fake->angefragtePortalIds,
        );
    }
}
