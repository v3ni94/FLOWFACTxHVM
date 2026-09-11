<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\HeizkostenVersorgung;
use App\Enums\ProvisionTyp;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Prüffälle aus dem Datenvertrag Abschnitt 3 über den Erfassungsassistenten
 * (Schritt 4), nicht nur über den RentCalculator direkt.
 */
final class WizardStep4RentTest extends TestCase
{
    use RefreshDatabase;

    private function postSchritt4(User $user, Listing $listing, array $daten): TestResponse
    {
        return $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 4]),
            array_merge([
                'aktion' => 'speichern',
                'provision_typ' => ProvisionTyp::Provisionsfrei->value,
            ], $daten)
        );
    }

    public function test_fall_a_zentral_ohne_enthalten_addiert_die_heizkosten(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create();

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => '800,00',
            'nebenkosten' => '200,00',
            'heizkosten' => '100,00',
            'heizkosten_in_nebenkosten_enthalten' => '0',
            'heizkosten_versorgung' => HeizkostenVersorgung::Zentral->value,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(110_000, $listing->price->fresh()->warmmiete_cent);
    }

    public function test_fall_b_zentral_mit_enthalten_addiert_die_heizkosten_nicht_erneut(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create();

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => '800,00',
            'nebenkosten' => '300,00',
            'heizkosten' => '100,00',
            'heizkosten_in_nebenkosten_enthalten' => '1',
            'heizkosten_versorgung' => HeizkostenVersorgung::Zentral->value,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(110_000, $listing->price->fresh()->warmmiete_cent);
    }

    public function test_fall_d_dezentral_ohne_heizkosten_erzeugt_den_versorgerhinweis(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create();

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => '800,00',
            'nebenkosten' => '150,00',
            'heizkosten' => '',
            'heizkosten_in_nebenkosten_enthalten' => '0',
            'heizkosten_versorgung' => HeizkostenVersorgung::Dezentral->value,
        ]);

        $response->assertSessionDoesntHaveErrors();
        $listing->refresh();
        $this->assertSame(95_000, $listing->price->fresh()->warmmiete_cent);
        $this->assertSame(HeizkostenVersorgung::Dezentral, $listing->heizkosten_versorgung);
    }

    public function test_fall_e_dezentral_mit_heizkosten_wird_abgelehnt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create();

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => '800,00',
            'nebenkosten' => '150,00',
            'heizkosten' => '50,00',
            'heizkosten_in_nebenkosten_enthalten' => '0',
            'heizkosten_versorgung' => HeizkostenVersorgung::Dezentral->value,
        ]);

        $response->assertSessionHasErrors('heizkosten');
        $this->assertNull($listing->price()->first());
    }

    public function test_fall_f_heizkosten_groesser_als_nebenkosten_wird_abgelehnt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create();

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => '800,00',
            'nebenkosten' => '200,00',
            'heizkosten' => '250,00',
            'heizkosten_in_nebenkosten_enthalten' => '1',
            'heizkosten_versorgung' => HeizkostenVersorgung::Zentral->value,
        ]);

        $response->assertSessionHasErrors('heizkosten');
    }

    public function test_ein_ungueltiges_betragsformat_wird_abgelehnt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create();

        $response = $this->postSchritt4($user, $listing, [
            'kaltmiete' => 'nicht-numerisch',
            'nebenkosten' => '200,00',
            'heizkosten_versorgung' => HeizkostenVersorgung::Zentral->value,
        ]);

        $response->assertSessionHasErrors('kaltmiete');
        $errors = session('errors');
        $this->assertStringContainsString('1.234,56', $errors->first('kaltmiete'));
    }

    public function test_ein_kaufobjekt_speichert_den_kaufpreis_ohne_warmmiete(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->kauf()->create();

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 4]),
            [
                'aktion' => 'weiter',
                'kaufpreis' => '325.000,00',
                'provision_typ' => ProvisionTyp::Provisionsfrei->value,
            ]
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 5]));

        $preis = $listing->price()->first();
        $this->assertSame(32_500_000, $preis->kaufpreis_cent);
        $this->assertNull($preis->warmmiete_cent);
    }
}
