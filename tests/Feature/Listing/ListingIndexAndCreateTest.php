<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\ListingStatus;
use App\Enums\Objektart;
use App\Enums\Vermarktungsart;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ListingIndexAndCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_die_objektuebersicht_rendert_die_angelegten_objekte(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->create(['titel' => 'Schöne Wohnung in Erkelenz', 'ort' => 'Erkelenz']);

        $response = $this->actingAs($user)->get(route('app.listings.index'));

        $response->assertOk();
        $response->assertSee($listing->objektnummer);
        $response->assertSee('Schöne Wohnung in Erkelenz');
    }

    public function test_die_objektuebersicht_kann_nach_status_gefiltert_werden(): void
    {
        $user = User::factory()->create();
        $entwurf = Listing::factory()->create(['status' => ListingStatus::Entwurf]);
        $archiviert = Listing::factory()->archiviert()->create();

        $response = $this->actingAs($user)->get(route('app.listings.index', ['status' => ListingStatus::Archiviert->value]));

        $response->assertOk();
        $response->assertSee($archiviert->objektnummer);
        $response->assertDontSee($entwurf->objektnummer);
    }

    public function test_die_objektuebersicht_kann_nach_vermarktungsart_gefiltert_werden(): void
    {
        $user = User::factory()->create();
        $miete = Listing::factory()->miete()->create();
        $kauf = Listing::factory()->kauf()->create();

        $response = $this->actingAs($user)->get(route('app.listings.index', ['vermarktungsart' => Vermarktungsart::Kauf->value]));

        $response->assertOk();
        $response->assertSee($kauf->objektnummer);
        $response->assertDontSee($miete->objektnummer);
    }

    public function test_die_objektuebersicht_kann_per_suche_nach_objektnummer_gefiltert_werden(): void
    {
        $user = User::factory()->create();
        $treffer = Listing::factory()->create(['ort' => 'Wegberg']);
        $kein_treffer = Listing::factory()->create(['ort' => 'Mönchengladbach']);

        $response = $this->actingAs($user)->get(route('app.listings.index', ['suche' => 'Wegberg']));

        $response->assertOk();
        $response->assertSee($treffer->objektnummer);
        $response->assertDontSee($kein_treffer->objektnummer);
    }

    public function test_die_objektuebersicht_kann_nach_bearbeiter_gefiltert_werden(): void
    {
        $user = User::factory()->create();
        $bearbeiter = User::factory()->create(['name' => 'Anna Beispiel']);
        $anderer = User::factory()->create();

        $eigenes = Listing::factory()->create(['bearbeiter_user_id' => $bearbeiter->id]);
        $fremdes = Listing::factory()->create(['bearbeiter_user_id' => $anderer->id]);

        $response = $this->actingAs($user)->get(route('app.listings.index', ['bearbeiter' => $bearbeiter->id]));

        $response->assertOk();
        $response->assertSee($eigenes->objektnummer);
        $response->assertDontSee($fremdes->objektnummer);
    }

    public function test_die_objektdetailseite_zeigt_keine_internen_marker_aber_die_historie_ist_verlinkt(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->vollstaendig()->create();
        $listing->internal()->create(['interne_notizen' => 'INTERN-MARKER-DETAILSEITE']);

        $response = $this->actingAs($user)->get(route('app.listings.show', $listing));

        $response->assertOk();
        $response->assertDontSee('INTERN-MARKER-DETAILSEITE');
        $response->assertSee(route('app.listings.history', $listing), false);
    }

    public function test_ein_neues_objekt_wird_als_entwurf_angelegt_und_leitet_zu_schritt_eins_weiter(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('app.listings.create'), [
            'vermarktungsart' => Vermarktungsart::Miete->value,
            'objektart' => Objektart::Wohnung->value,
        ]);

        $listing = Listing::query()->latest('id')->first();

        $this->assertNotNull($listing);
        $this->assertSame(ListingStatus::Entwurf, $listing->status);
        $this->assertSame('DE', $listing->land);
        $this->assertSame($user->id, $listing->erstellt_von_user_id);
        $this->assertSame($user->id, $listing->bearbeiter_user_id);
        $this->assertSame($user->id, $listing->ansprechpartner_user_id);
        $this->assertMatchesRegularExpression('/^MF-\d{4}-\d{4}$/', $listing->objektnummer);

        $response->assertRedirect(route('app.listings.step', ['listing' => $listing, 'schritt' => 1]));
    }

    public function test_die_anlage_ohne_vermarktungsart_schlaegt_fehl(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('app.listings.create'), [
            'objektart' => Objektart::Wohnung->value,
        ]);

        $response->assertSessionHasErrors('vermarktungsart');
        $this->assertSame(0, Listing::query()->count());
    }
}
