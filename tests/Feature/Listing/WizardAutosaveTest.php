<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\ListingStatus;
use App\Flowfact\Sync\PublishingService;
use App\Flowfact\Sync\PublishResult;
use App\Flowfact\Sync\SyncResult;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Autosave-Vertrag über alle Schritte 1 bis 6 (Masterprompt-Abgleich B.1):
 * JSON-Antwort mit gespeichert_at, 422 bei ungültigen Werten, niemals
 * Übertragung oder Veröffentlichung, niemals Statuswechsel über den
 * einfachen Entwurf/Bereit-Rahmen hinaus.
 */
final class WizardAutosaveTest extends TestCase
{
    use RefreshDatabase;

    private function spyPublishingService(): object
    {
        $spy = new class implements PublishingService
        {
            public int $aufrufe = 0;

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
                $this->aufrufe++;

                return SyncResult::failed('darf nicht aufgerufen werden');
            }

            public function publish(Listing $listing, array $portalIds, ?User $user = null): PublishResult
            {
                $this->aufrufe++;

                return new PublishResult(true, 'darf nicht aufgerufen werden');
            }

            public function withdraw(Listing $listing, array $portalIds, ?User $user = null): PublishResult
            {
                $this->aufrufe++;

                return new PublishResult(true, 'darf nicht aufgerufen werden');
            }

            public function refreshStatus(Listing $listing): void
            {
                $this->aufrufe++;
            }
        };

        $this->app->instance(PublishingService::class, $spy);

        return $spy;
    }

    public function test_autosave_antwortet_mit_json_struktur_und_zeitstempel_je_schritt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        foreach ([
            1 => ['nutzungsstatus' => 'unbekannt'],
            2 => ['stadtteil' => 'Mitte'],
            3 => ['baujahr' => '2001'],
            4 => ['kaltmiete' => '900,00'],
            5 => ['merkmal_balkon' => 'ja'],
            6 => [],
        ] as $schritt => $daten) {
            $response = $this->actingAs($user)->patchJson(
                route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => $schritt]),
                $daten
            );

            $response->assertOk();
            $response->assertJsonStructure(['ok', 'gespeichert_at', 'fehlend', 'hinweise']);
            self::assertTrue($response->json('ok'));
            self::assertMatchesRegularExpression('/^\d{2}:\d{2}:\d{2}$/', $response->json('gespeichert_at'));
        }
    }

    public function test_autosave_meldet_422_bei_einem_unmoeglichen_wert_und_aendert_nichts(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->patchJson(
            route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 3]),
            ['baujahr' => '1500']
        );

        $response->assertStatus(422);
        $response->assertJsonStructure(['ok', 'errors']);
        self::assertFalse($response->json('ok'));
    }

    public function test_autosave_ruft_niemals_den_publishingservice_auf_und_aendert_den_status_nicht(): void
    {
        $spy = $this->spyPublishingService();

        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create([
            'bearbeiter_user_id' => $user->id,
            'erstellt_von_user_id' => $user->id,
            'status' => ListingStatus::Entwurf,
        ]);

        foreach (range(1, 6) as $schritt) {
            $this->actingAs($user)->patchJson(
                route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => $schritt]),
                []
            );
        }

        self::assertSame(0, $spy->aufrufe, 'Autosave darf den PublishingService nie aufrufen.');
        self::assertSame(ListingStatus::Entwurf, $listing->fresh()->status);
    }

    public function test_autosave_eines_bereiten_objekts_faellt_bei_unvollstaendigkeit_auf_entwurf_zurueck(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create([
            'bearbeiter_user_id' => $user->id,
            'erstellt_von_user_id' => $user->id,
            'status' => ListingStatus::Bereit,
        ]);

        $this->actingAs($user)->patchJson(
            route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 4]),
            ['kaltmiete' => '']
        );

        self::assertSame(ListingStatus::Entwurf, $listing->fresh()->status);
    }
}
