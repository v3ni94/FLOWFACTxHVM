<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\ListingStatus;
use App\Flowfact\Sync\NullPublishingService;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class WizardStep8PublishTest extends TestCase
{
    use RefreshDatabase;

    public function test_die_pruefseite_zeigt_die_fehlenden_felder_eines_unvollstaendigen_objekts(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['titel' => null]);

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 8]));

        $response->assertOk();
        $response->assertSee('Titel');
    }

    public function test_interne_daten_erscheinen_niemals_auf_der_pruefseite(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create();

        $listing->internal()->create([
            'eigentuemer_name' => 'INTERN-MARKER-NAME',
            'eigentuemer_kontakt' => 'INTERN-MARKER-KONTAKT',
            'verwaltungsobjekt_referenz' => 'INTERN-MARKER-REFERENZ',
            'interne_notizen' => 'INTERN-MARKER-NOTIZ',
            'schluessel_hinweis' => 'INTERN-MARKER-SCHLUESSEL',
            'besichtigung_intern' => 'INTERN-MARKER-BESICHTIGUNG',
            'kalkulation_notiz' => 'INTERN-MARKER-KALKULATION',
        ]);

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 8]));

        $response->assertOk();
        $response->assertDontSee('INTERN-MARKER-NAME');
        $response->assertDontSee('INTERN-MARKER-KONTAKT');
        $response->assertDontSee('INTERN-MARKER-REFERENZ');
        $response->assertDontSee('INTERN-MARKER-NOTIZ');
        $response->assertDontSee('INTERN-MARKER-SCHLUESSEL');
        $response->assertDontSee('INTERN-MARKER-BESICHTIGUNG');
        $response->assertDontSee('INTERN-MARKER-KALKULATION');
    }

    public function test_die_veroeffentlichung_eines_unvollstaendigen_objekts_wird_serverseitig_blockiert(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['titel' => null]);

        $response = $this->actingAs($user)->post(route('app.listings.publish', $listing), [
            'portale' => ['immoscout24'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Titel', session('error'));

        $this->assertSame(ListingStatus::Entwurf, $listing->fresh()->status);
    }

    public function test_die_veroeffentlichung_ohne_portalauswahl_schlaegt_fehl(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create();

        $response = $this->actingAs($user)->post(route('app.listings.publish', $listing), []);

        $response->assertSessionHasErrors('portale');
    }

    public function test_die_veroeffentlichung_eines_vollstaendigen_objekts_zeigt_die_nichtkonfiguriert_meldung_und_aendert_den_status_nicht(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create(['status' => ListingStatus::Bereit]);

        $response = $this->actingAs($user)->post(route('app.listings.publish', $listing), [
            'portale' => ['immoscout24'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', NullPublishingService::MELDUNG);

        $this->assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    public function test_ein_vollstaendiges_objekt_kann_auf_bereit_gesetzt_werden(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create();

        $response = $this->actingAs($user)->post(route('app.listings.status.bereit', $listing));

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $this->assertSame(ListingStatus::Bereit, $listing->fresh()->status);
    }

    public function test_ein_unvollstaendiges_objekt_kann_nicht_auf_bereit_gesetzt_werden(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['titel' => null]);

        $response = $this->actingAs($user)->post(route('app.listings.status.bereit', $listing));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(ListingStatus::Entwurf, $listing->fresh()->status);
    }
}
