<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Domain\Listing\ReleaseService;
use App\Domain\Settings\SettingsRepository;
use App\Enums\ListingStatus;
use App\Enums\ReleaseAktion;
use App\Flowfact\Client\SettingsTokenProvider;
use App\Flowfact\Sync\Jobs\TransferListingJob;
use App\Flowfact\Sync\ListingSyncService;
use App\Flowfact\Sync\PublishingService;
use App\Flowfact\Sync\PublishResult;
use App\Flowfact\Sync\ReleaseGuard;
use App\Flowfact\Sync\SyncResult;
use App\Models\Listing;
use App\Models\ListingRelease;
use App\Models\TransferLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Prüfbericht 2026-09-12, Befund 8: ReviewController::transfer prüft Status
 * und Konfiguration, bevor eine Freigabeversion entsteht, verwirft eine
 * Freigabeversion, die FLOWFACT nie erreicht hat, und übernimmt für eine
 * wartende Veröffentlichung die Portale der Vorgängerfreigabe.
 */
final class TransferGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_ein_entwurf_erzeugt_beim_uebertragen_keine_freigabeversion(): void
    {
        Http::fake();
        $user = User::factory()->admin()->create();
        $listing = Listing::factory()->miete()->vollstaendig()->create(['status' => ListingStatus::Entwurf]);

        $antwort = $this->actingAs($user)->post(route('app.listings.transfer', $listing));

        $antwort->assertSessionHas('error');
        self::assertSame(0, ListingRelease::query()->where('listing_id', $listing->id)->count(), 'Ein Entwurf darf beim Übertragen keine Freigabeversion erzeugen.');
        Http::assertNothingSent();
    }

    public function test_ohne_flowfact_konfiguration_entsteht_keine_freigabeversion(): void
    {
        Http::fake();
        $user = User::factory()->admin()->create();
        $listing = Listing::factory()->miete()->vollstaendig()->create(['status' => ListingStatus::Bereit]);

        // Kein Token hinterlegt: der Container liefert NullPublishingService.
        $antwort = $this->actingAs($user)->post(route('app.listings.transfer', $listing));

        $antwort->assertSessionHas('error');
        self::assertSame(0, ListingRelease::query()->where('listing_id', $listing->id)->count());
        Http::assertNothingSent();
    }

    public function test_eine_fehlgeschlagene_uebertragung_ohne_flowfact_kontakt_verwirft_die_freigabeversion_und_der_wartende_job_bleibt_gueltig(): void
    {
        Http::fake();
        $user = User::factory()->admin()->create();
        $listing = Listing::factory()->miete()->vollstaendig()->create(['status' => ListingStatus::Bereit, 'flowfact_schema' => null]);
        app(SettingsRepository::class)->setSecret(SettingsTokenProvider::TOKEN_KEY, 'test-token');
        // Bewusst kein Schema hinterlegt: die Übertragung scheitert, ohne
        // FLOWFACT tatsächlich zu kontaktieren (kein HTTP-Aufruf).

        // Freigabe 1 zur Veröffentlichung, ein Fortsetzungsjob wartet (z. B.
        // weil der Bildupload ins Zeitlimit lief).
        $release1 = app(ReleaseService::class)->freigeben($listing->fresh(['price', 'energy', 'media']), $user, ReleaseAktion::Veroeffentlichen, ['immoscout24']);

        $antwort = $this->actingAs($user)->post(route('app.listings.transfer', $listing));
        $antwort->assertSessionHas('error');

        self::assertSame(1, ListingRelease::query()->where('listing_id', $listing->id)->count(), 'Die fehlgeschlagene Übertragung darf keine Freigabeversion hinterlassen.');
        self::assertSame($release1->id, app(ReleaseService::class)->latest($listing->fresh())->id);
        Http::assertNothingSent();

        (new TransferListingJob($listing->id, $user->id, false, $release1->id, true))
            ->handle(app(ListingSyncService::class), app(ReleaseGuard::class));

        self::assertNull(
            TransferLog::query()->where('listing_id', $listing->id)->where('zusammenfassung', ReleaseGuard::MELDUNG_VERALTET)->first(),
            'Der wartende Veröffentlichungsjob darf nach der verworfenen Freigabe nicht als veraltet gelten.'
        );
    }

    public function test_eine_erfolgreiche_uebertragung_uebernimmt_die_portale_einer_wartenden_veroeffentlichung(): void
    {
        $user = User::factory()->admin()->create();
        $listing = Listing::factory()->miete()->vollstaendig()->create(['status' => ListingStatus::Bereit]);

        $release1 = app(ReleaseService::class)->freigeben($listing->fresh(['price', 'energy', 'media']), $user, ReleaseAktion::Veroeffentlichen, ['immoscout24']);

        $fake = new class implements PublishingService
        {
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
                return new SyncResult(true, 'In FLOWFACT gespeichert.', entityId: 'ent-1');
            }

            public function publish(Listing $listing, array $portalIds, ?User $user = null): PublishResult
            {
                return new PublishResult(true, 'ok');
            }

            public function withdraw(Listing $listing, array $portalIds, ?User $user = null): PublishResult
            {
                return new PublishResult(true, 'ok');
            }

            public function refreshStatus(Listing $listing): void {}
        };
        $this->app->instance(PublishingService::class, $fake);

        $this->actingAs($user)->post(route('app.listings.transfer', $listing))->assertSessionHas('status');

        $release2 = ListingRelease::query()->where('listing_id', $listing->id)->orderByDesc('version')->first();
        self::assertNotSame($release1->id, $release2->id);
        self::assertSame(['immoscout24'], $release2->portalIds(), 'Die neue Freigabe muss die Portale der wartenden Veröffentlichung übernehmen.');
    }
}
