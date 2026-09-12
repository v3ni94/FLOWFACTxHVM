<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\HeizkostenStruktur;
use App\Enums\HeizkostenVersorgung;
use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prüfbericht 2026-09-11, Befund 2 (weiterhin gültig unter der neuen
 * Schrittfolge, Masterprompt-Abgleich B.1): heizkosten_versorgung wird bei
 * Miete ausschließlich aus der in Schritt 4 gewählten Kostenstruktur
 * abgeleitet (App\Domain\Listing\PriceStructure). Ein späterer, nur
 * teilweiser Autosave desselben Schritts (z. B. nur die Struktur-Kachel
 * wechselt) darf keine widersprüchliche Warmmiete stehen lassen und keine
 * unbehandelte InvalidRentInputException auslösen.
 */
final class WizardHeizkostenVersorgungTest extends TestCase
{
    use RefreshDatabase;

    public function test_ein_teilweiser_autosave_der_nur_die_struktur_auf_eigenen_vertrag_umstellt_nullt_die_heizkosten(): void
    {
        $this->withoutExceptionHandling();
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create([
            'status' => ListingStatus::Entwurf,
            'bearbeiter_user_id' => $user->id,
            'erstellt_von_user_id' => $user->id,
        ]);

        // Erster Autosave: zentral, zusätzlich, Heizkosten 100,00 -> Warmmiete 1.100,00.
        $this->actingAs($user)->patchJson(
            route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 4]),
            [
                'kaltmiete' => '800,00',
                'nebenkosten' => '200,00',
                'heizkosten' => '100,00',
                'heizkosten_struktur' => 'zusaetzlich',
            ]
        )->assertOk();

        self::assertSame(110_000, $listing->price()->first()->warmmiete_cent);

        // Zweiter, teilweiser Autosave: nur die Struktur-Kachel wechselt auf
        // "eigener Versorgungsvertrag", Kaltmiete/Nebenkosten werden nicht
        // erneut mitgesendet (autosave ist immer teilweise möglich).
        $this->actingAs($user)->patchJson(
            route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 4]),
            ['heizkosten_struktur' => 'eigener_vertrag']
        )->assertOk();

        $listing->refresh();
        self::assertSame(HeizkostenVersorgung::Dezentral, $listing->heizkosten_versorgung);

        $preis = $listing->price()->first();
        self::assertNull($preis->heizkosten_cent, 'Heizkosten müssen bei eigenem Versorgungsvertrag genullt werden.');
        self::assertFalse($preis->heizkosten_in_nebenkosten_enthalten);
        self::assertSame(100_000, $preis->warmmiete_cent, 'Warmmiete muss ohne Heizkosten neu berechnet werden (K+N).');

        // Jede Seite des Objekts bleibt erreichbar (keine InvalidRentInputException mehr).
        $this->actingAs($user)->get(route('app.listings.show', $listing))->assertOk();
        $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 4]))->assertOk();
    }

    public function test_wechsel_auf_eigenen_vertrag_ohne_erfasste_heizkosten_aendert_die_warmmiete_nicht_unerwartet(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->mitPreisen()->create([
            'status' => ListingStatus::Entwurf,
            'bearbeiter_user_id' => $user->id,
            'erstellt_von_user_id' => $user->id,
        ]);

        $listing->price()->update([
            'heizkosten_cent' => null,
            'heizkosten_in_nebenkosten_enthalten' => false,
            'heizkosten_struktur' => HeizkostenStruktur::Zusaetzlich,
        ]);

        $this->actingAs($user)->patchJson(
            route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 4]),
            ['heizkosten_struktur' => 'eigener_vertrag']
        )->assertOk();

        self::assertSame(
            $listing->price()->first()->kaltmiete_cent + $listing->price()->first()->nebenkosten_cent,
            $listing->price()->first()->warmmiete_cent
        );
    }
}
