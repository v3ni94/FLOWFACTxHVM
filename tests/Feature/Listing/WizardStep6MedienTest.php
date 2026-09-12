<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Schritt 6: Bilder und Unterlagen (Masterprompt-Abgleich B.1 Schritt 6). Die
 * eigentlichen Medienaktionen (Upload, Drehen, Freigabe, HEIC-Ablehnung)
 * testet tests/Feature/Media/**; hier geht es um die Anzeige des Schritts und
 * die Navigation.
 */
final class WizardStep6MedienTest extends TestCase
{
    use RefreshDatabase;

    public function test_die_medien_werden_nach_kategorie_gruppiert_angezeigt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $erstesFoto = ListingMedia::factory()->for($listing)->bild()->create(['sortierung' => 0, 'dateiname_original' => 'titelbild.jpg']);
        ListingMedia::factory()->for($listing)->bild()->create(['sortierung' => 1, 'dateiname_original' => 'zweites.jpg']);
        ListingMedia::factory()->for($listing)->dokument()->create(['dateiname_original' => 'expose.pdf']);

        $response = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 6]));

        $response->assertOk();
        $response->assertSee('titelbild.jpg');
        $response->assertSee('zweites.jpg');
        $response->assertSee('expose.pdf');
        $response->assertSee('Titelbild');
    }

    public function test_weiter_aus_schritt_sechs_fuehrt_zu_schritt_sieben(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->post(
            route('app.listings.step.store', ['listing' => $listing, 'schritt' => 6]),
            ['aktion' => 'weiter']
        );

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 7]));
    }

    public function test_autosave_in_schritt_sechs_antwortet_ok_ohne_daten(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $response = $this->actingAs($user)->patchJson(
            route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 6]),
            []
        );

        $response->assertOk();
        self::assertTrue($response->json('ok'));
    }
}
