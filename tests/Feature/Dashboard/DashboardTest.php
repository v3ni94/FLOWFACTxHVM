<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ListingStatus;
use App\Enums\SyncStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_das_dashboard_zeigt_die_kennzahlen_und_die_zuletzt_geaenderten_objekte(): void
    {
        $user = User::factory()->create();

        Listing::factory()->create(['status' => ListingStatus::Entwurf]);
        Listing::factory()->create(['status' => ListingStatus::Entwurf]);
        Listing::factory()->vollstaendig()->create(['status' => ListingStatus::Bereit]);
        $veroeffentlicht = Listing::factory()->vollstaendig()->create(['status' => ListingStatus::Veroeffentlicht]);

        $fehlgeschlagen = Listing::factory()->vollstaendig()->create();
        $fehlgeschlagen->flowfactLink()->create([
            'flowfact_schema' => 'immobilie',
            'sync_status' => SyncStatus::Fehlgeschlagen,
        ]);

        $response = $this->actingAs($user)->get(route('app.dashboard'));

        $response->assertOk();
        $response->assertSee('Entwürfe');
        $response->assertSee('2');
        $response->assertSee('Bereit');
        $response->assertSee('Veröffentlicht');
        $response->assertSee('Übertragungsfehler');
        $response->assertSee($veroeffentlicht->objektnummer);
    }

    public function test_ein_veraltetes_scheduler_lebenszeichen_zeigt_eine_warnung(): void
    {
        $user = User::factory()->create();
        Cache::put('scheduler.last_run', now()->subMinutes(30));

        $response = $this->actingAs($user)->get(route('app.dashboard'));

        $response->assertOk();
        $response->assertSee('Hintergrundverarbeitung meldet sich seit');
    }

    public function test_ein_aktuelles_scheduler_lebenszeichen_zeigt_keine_warnung(): void
    {
        $user = User::factory()->create();
        Cache::put('scheduler.last_run', now()->subMinutes(2));

        $response = $this->actingAs($user)->get(route('app.dashboard'));

        $response->assertOk();
        $response->assertDontSee('Hintergrundverarbeitung meldet sich seit');
    }
}
