<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Models\Listing;
use App\Models\ListingMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Prüfbericht 2026-09-12, Befund 1: Schritt 6 muss die Bild- und
     * Dokumentlinks signieren (wie die Detailseite), sonst antwortet
     * die Route hinter der signed-Middleware mit 403.
     */
    public function test_schritt6_verlinkt_medien_signiert_und_die_links_liefern_200(): void
    {
        Storage::fake('media');
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);
        $bild = ListingMedia::factory()->for($listing)->bild()->create(['dateiname_original' => 'titelbild.jpg']);
        Storage::disk('media')->put($bild->pfad, 'x');
        $pdf = ListingMedia::factory()->for($listing)->dokument()->create();
        Storage::disk('media')->put($pdf->pfad, '%PDF');

        $seite = $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 6]));
        $seite->assertOk();
        $html = $seite->getContent();

        preg_match('#src="([^"]*/medien/'.$bild->id.'/vorschau[^"]*)"#', $html, $m);
        self::assertNotEmpty($m, 'Bild-URL in Schritt 6 nicht gefunden');
        $bildUrl = html_entity_decode($m[1]);
        self::assertStringContainsString('signature=', $bildUrl);

        preg_match('#href="([^"]*/medien/'.$pdf->id.'/original[^"]*)"#', $html, $p);
        self::assertNotEmpty($p, 'PDF-Link in Schritt 6 nicht gefunden');
        $pdfUrl = html_entity_decode($p[1]);
        self::assertStringContainsString('signature=', $pdfUrl);

        $this->actingAs($user)->get($bildUrl)->assertOk();
        $this->actingAs($user)->get($pdfUrl)->assertOk();
    }
}
