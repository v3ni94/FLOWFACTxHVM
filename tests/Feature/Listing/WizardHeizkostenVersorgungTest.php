<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\HeizkostenVersorgung;
use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prüfbericht 2026-09-11, Befund 2: Schritt 2 speicherte
 * `heizkosten_versorgung` unabhängig von Schritt 4. Wechselte der Wert auf
 * "dezentral", nachdem in Schritt 4 bereits Heizkosten erfasst worden waren,
 * blieb eine widersprüchliche Warmmiete (K+N+H) stehen, und CompletenessCheck
 * warf beim nächsten Aufruf eine unbehandelte InvalidRentInputException
 * (Detailseite und alle Schritte antworteten mit HTTP 500). Portiert aus
 * Poc06HeizkostenDezentralTest.php, mit umgekehrten Erwartungen: der Wechsel
 * nullt die Heizkosten und berechnet die Warmmiete neu, das Objekt bleibt
 * über die gesamte Oberfläche bedienbar.
 */
final class WizardHeizkostenVersorgungTest extends TestCase
{
    use RefreshDatabase;

    public function test_wechsel_auf_dezentral_nach_erfassten_heizkosten_nullt_sie_und_berechnet_die_warmmiete_neu(): void
    {
        $this->withoutExceptionHandling();
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->create([
            'status' => ListingStatus::Entwurf,
            'heizkosten_versorgung' => HeizkostenVersorgung::Zentral,
            'erstellt_von_user_id' => $user->id,
        ]);

        // Schritt 4: zentral, Heizkosten 100,00 -> Warmmiete 1.100,00
        $this->actingAs($user)->post(route('app.listings.step.store', ['listing' => $listing, 'schritt' => 4]), [
            'kaltmiete' => '800,00',
            'nebenkosten' => '200,00',
            'heizkosten' => '100,00',
            'heizkosten_in_nebenkosten_enthalten' => '0',
            'heizkosten_versorgung' => 'zentral',
            'provision_typ' => 'provisionsfrei',
        ])->assertRedirect();

        self::assertSame(110_000, $listing->price()->first()->warmmiete_cent);

        // Schritt 2: Heizkostenversorgung auf dezentral geändert, ohne Schritt 4 neu zu speichern.
        $this->actingAs($user)->post(route('app.listings.step.store', ['listing' => $listing, 'schritt' => 2]), [
            'wohnflaeche_qm' => '65',
            'zimmer' => '3',
            'heizkosten_versorgung' => 'dezentral',
            'verfuegbar_ab_typ' => 'sofort',
        ])->assertRedirect();

        $listing->refresh();
        self::assertSame(HeizkostenVersorgung::Dezentral, $listing->heizkosten_versorgung);

        $preis = $listing->price()->first();
        self::assertNull($preis->heizkosten_cent, 'Heizkosten müssen bei dezentraler Versorgung genullt werden.');
        self::assertFalse($preis->heizkosten_in_nebenkosten_enthalten);
        self::assertSame(100_000, $preis->warmmiete_cent, 'Warmmiete muss ohne Heizkosten neu berechnet werden (K+N).');

        // Jede Seite des Objekts bleibt erreichbar (keine InvalidRentInputException mehr).
        $this->actingAs($user)->get(route('app.listings.show', $listing))->assertOk();
        $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 2]))->assertOk();
        $this->actingAs($user)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 4]))->assertOk();
    }

    public function test_wechsel_auf_dezentral_ohne_erfasste_heizkosten_aendert_die_warmmiete_nicht(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->miete()->mitPreisen()->create([
            'status' => ListingStatus::Entwurf,
            'heizkosten_versorgung' => HeizkostenVersorgung::Zentral,
            'erstellt_von_user_id' => $user->id,
        ]);

        // Heizkosten bereits null, Warmmiete bewusst auf einen Wert gesetzt, den
        // eine Neuberechnung (Kaltmiete + Nebenkosten) nicht treffen würde, um
        // zu belegen, dass ohne erfasste Heizkosten gar nicht neu gerechnet wird.
        $listing->price()->update([
            'heizkosten_cent' => null,
            'heizkosten_in_nebenkosten_enthalten' => false,
            'warmmiete_cent' => 500_000,
        ]);

        $this->actingAs($user)->post(route('app.listings.step.store', ['listing' => $listing, 'schritt' => 2]), [
            'wohnflaeche_qm' => '65',
            'zimmer' => '3',
            'heizkosten_versorgung' => 'dezentral',
            'verfuegbar_ab_typ' => 'sofort',
        ])->assertRedirect();

        self::assertSame(500_000, $listing->price()->first()->warmmiete_cent);
    }
}
