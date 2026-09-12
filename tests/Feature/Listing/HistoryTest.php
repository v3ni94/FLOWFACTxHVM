<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Models\Listing;
use App\Models\ListingChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Änderungshistorie eines Objekts (Masterprompt-Abgleich B.6). Interne
 * Felder (listing_internals) werden protokolliert, aber nur Benutzern mit
 * Bearbeitungsrecht angezeigt, nie exportiert.
 */
final class HistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_die_historie_zeigt_aenderungen_mit_benutzer_und_zeitpunkt(): void
    {
        $user = User::factory()->create(['name' => 'Erika Musterfrau']);
        $listing = Listing::factory()->create();

        ListingChange::query()->create([
            'listing_id' => $listing->id,
            'user_id' => $user->id,
            'feld' => 'listings.titel',
            'alt' => 'Alter Titel',
            'neu' => 'Neuer Titel',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('app.listings.history', $listing));

        $response->assertOk();
        $response->assertSee('Erika Musterfrau');
        $response->assertSee('Alter Titel');
        $response->assertSee('Neuer Titel');
    }

    public function test_ein_bearbeitungsberechtigter_benutzer_sieht_interne_aenderungen(): void
    {
        $bearbeiter = User::factory()->create();
        $listing = Listing::factory()->create([
            'bearbeiter_user_id' => $bearbeiter->id,
            'erstellt_von_user_id' => $bearbeiter->id,
        ]);

        ListingChange::query()->create([
            'listing_id' => $listing->id,
            'user_id' => $bearbeiter->id,
            'feld' => 'listing_internals.interne_notizen',
            'alt' => null,
            'neu' => 'INTERN-MARKER-NOTIZ',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($bearbeiter)->get(route('app.listings.history', $listing));

        $response->assertOk();
        $response->assertSee('INTERN-MARKER-NOTIZ');
    }

    public function test_ein_fremder_mitarbeiter_ohne_bearbeitungsrecht_sieht_keine_internen_aenderungen(): void
    {
        $bearbeiter = User::factory()->create();
        $anderer = User::factory()->create();
        $listing = Listing::factory()->create([
            'bearbeiter_user_id' => $bearbeiter->id,
            'erstellt_von_user_id' => $bearbeiter->id,
        ]);

        ListingChange::query()->create([
            'listing_id' => $listing->id,
            'user_id' => $bearbeiter->id,
            'feld' => 'listing_internals.interne_notizen',
            'alt' => null,
            'neu' => 'INTERN-MARKER-NOTIZ',
            'created_at' => now(),
        ]);

        ListingChange::query()->create([
            'listing_id' => $listing->id,
            'user_id' => $bearbeiter->id,
            'feld' => 'listings.titel',
            'alt' => null,
            'neu' => 'Öffentlicher Titel',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($anderer)->get(route('app.listings.history', $listing));

        $response->assertOk();
        $response->assertDontSee('INTERN-MARKER-NOTIZ');
        $response->assertSee('Öffentlicher Titel');
    }

    public function test_ein_leser_kann_die_historie_einsehen_aber_keine_internen_aenderungen(): void
    {
        $leser = User::factory()->leser()->create();
        $listing = Listing::factory()->create();

        ListingChange::query()->create([
            'listing_id' => $listing->id,
            'user_id' => null,
            'feld' => 'listing_internals.interne_notizen',
            'alt' => null,
            'neu' => 'INTERN-MARKER-NOTIZ',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($leser)->get(route('app.listings.history', $listing));

        $response->assertOk();
        $response->assertDontSee('INTERN-MARKER-NOTIZ');
    }
}
