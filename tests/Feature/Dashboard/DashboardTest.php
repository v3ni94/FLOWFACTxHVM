<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Enums\SyncStatus;
use App\Models\Listing;
use App\Models\ListingPortalPublication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Übersicht (Masterprompt Abschnitt 7): Startbutton, eigene Entwürfe,
 * Kennzahlen mit Verlinkung auf die gefilterte Objektübersicht.
 */
final class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_das_dashboard_zeigt_die_kennzahlen_und_meine_entwuerfe(): void
    {
        $user = User::factory()->create();

        Listing::factory()->create(['status' => ListingStatus::Entwurf, 'bearbeiter_user_id' => $user->id]);
        $eigenerEntwurf = Listing::factory()->create(['status' => ListingStatus::Entwurf, 'bearbeiter_user_id' => $user->id]);
        Listing::factory()->create(['status' => ListingStatus::Entwurf]);

        $veroeffentlicht = Listing::factory()->vollstaendig()->create(['status' => ListingStatus::Veroeffentlicht]);
        ListingPortalPublication::factory()->for($veroeffentlicht)->create(['status' => PortalStatus::Aktiv]);

        $fehlgeschlagen = Listing::factory()->vollstaendig()->create();
        $fehlgeschlagen->flowfactLink()->create([
            'flowfact_schema' => 'immobilie',
            'sync_status' => SyncStatus::Fehlgeschlagen,
        ]);

        $response = $this->actingAs($user)->get(route('app.dashboard'));

        $response->assertOk();
        $response->assertSee('Immobilien einfach erfassen und veröffentlichen');
        $response->assertSee('Meine Entwürfe');
        $response->assertSee('Alle Immobilien');
        $response->assertSee('Veröffentlichungen (aktiv)');
        $response->assertSee('Übertragungsfehler');
        $response->assertSee($eigenerEntwurf->objektnummer);
    }

    public function test_ein_mitarbeiter_sieht_nur_eigene_entwuerfe_in_der_liste(): void
    {
        $user = User::factory()->create();
        $anderer = User::factory()->create();

        $eigener = Listing::factory()->create(['bearbeiter_user_id' => $user->id]);
        $fremder = Listing::factory()->create(['bearbeiter_user_id' => $anderer->id]);

        $response = $this->actingAs($user)->get(route('app.dashboard'));

        $response->assertOk();
        $response->assertSee($eigener->objektnummer);
        $response->assertDontSee($fremder->objektnummer);
    }

    public function test_ein_leser_sieht_keinen_startbutton(): void
    {
        $leser = User::factory()->leser()->create();

        $response = $this->actingAs($leser)->get(route('app.dashboard'));

        $response->assertOk();
        $response->assertDontSee('Müller FLOW starten');
    }

    public function test_ein_mitarbeiter_sieht_den_startbutton(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('app.dashboard'));

        $response->assertOk();
        $response->assertSee('Müller FLOW starten');
    }

    public function test_der_startbutton_legt_einen_entwurf_an(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('app.listings.create'), [
            'vermarktungsart' => 'miete',
            'objektart' => 'wohnung',
        ]);

        $listing = Listing::query()->latest('id')->first();
        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 1]));
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
