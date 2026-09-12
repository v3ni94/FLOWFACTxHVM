<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Domain\Listing\CompletenessCheck;
use App\Enums\EnergieausweisStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Schritt 5: Ausstattung und Energieausweis (Masterprompt-Abgleich B.1
 * Schritt 5, B.5).
 */
final class WizardStep5AusstattungTest extends TestCase
{
    use RefreshDatabase;

    public function test_merkmale_werden_als_dreiwertige_zeichenketten_gespeichert(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 5]),
            [
                'aktion' => 'weiter',
                'merkmal_balkon' => 'ja',
                'merkmal_garten' => 'nein',
            ]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 6]));

        $listing->refresh();
        self::assertSame('ja', $listing->ausstattung['balkon']);
        self::assertSame('nein', $listing->ausstattung['garten']);
    }

    public function test_ein_nicht_beantwortetes_merkmal_wird_nie_als_nein_gespeichert(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create([
            'bearbeiter_user_id' => $user->id,
            'erstellt_von_user_id' => $user->id,
            'ausstattung' => [],
        ]);

        $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 5]),
            ['aktion' => 'speichern', 'merkmal_balkon' => 'ja']
        );

        $listing->refresh();
        self::assertSame('unbekannt', $listing->merkmal('terrasse')->value);
        self::assertNotSame('nein', $listing->merkmal('terrasse')->value);
    }

    public function test_ein_ungueltiger_merkmalwert_wird_abgelehnt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 5]),
            ['aktion' => 'speichern', 'merkmal_balkon' => 'vielleicht']
        );

        $response->assertSessionHasErrors('merkmal_balkon');
    }

    public function test_energieausweis_status_vorhanden_verlangt_ausweistyp_und_kennwert_erst_vor_veroeffentlichung(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 5]),
            ['aktion' => 'speichern', 'energieausweis_status' => 'vorhanden']
        );

        // Der Entwurf darf ohne Ausweistyp/Kennwert gespeichert werden.
        $response->assertSessionDoesntHaveErrors();
        $listing->refresh();
        self::assertSame(EnergieausweisStatus::Vorhanden, $listing->energy->status);

        $vollstaendigkeit = app(CompletenessCheck::class)->check($listing);
        self::assertArrayHasKey('energie.ausweistyp', $vollstaendigkeit->fehlend);
        self::assertArrayHasKey('energie.kennwert_kwh', $vollstaendigkeit->fehlend);
    }

    public function test_energieausweis_status_noch_nicht_vorhanden_blockiert_die_veroeffentlichung(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 5]),
            ['aktion' => 'speichern', 'energieausweis_status' => 'noch_nicht_vorhanden']
        );

        $listing->refresh();
        self::assertTrue(app(CompletenessCheck::class)->blockiert($listing));
    }

    public function test_energieausweis_wird_fuer_grundstueck_nicht_angezeigt_und_nicht_gespeichert(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->grundstueck()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 5]));
        $response->assertOk();
        $response->assertDontSee('energieausweis_status', false);
        $response->assertDontSee('Energieausweisstatus');

        $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 5]),
            ['aktion' => 'speichern', 'energieausweis_status' => 'vorhanden']
        );

        self::assertNull($listing->fresh()->energy);
    }

    public function test_einbaukueche_mitvermietet_wird_bei_miete_gespeichert(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);
        $listing->update(['ausstattung' => ['einbaukueche' => 'ja']]);

        $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 5]),
            ['aktion' => 'speichern', 'merkmal_einbaukueche' => 'ja', 'einbaukueche_mitvermietet' => '1']
        );

        self::assertTrue($listing->fresh()->einbaukueche_mitvermietet);
    }
}
