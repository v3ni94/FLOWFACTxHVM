<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\PortalStatus;
use App\Http\Controllers\App\Support\PortalSummary;
use App\Models\Listing;
use App\Models\ListingPortalPublication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prüfbericht 2026-09-12, Befund 7: die drei neuen Portalstatus
 * (manuelle_freigabe_erforderlich, deaktivierung_angefordert,
 * deaktivierung_bestaetigt) müssen in Übersicht, Detailseite und Prüfseite
 * mit ihrem deutschen Label erscheinen, nie als roher Enum-Wert.
 */
final class PortalSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function listingMitNeuenPortalstatus(): Listing
    {
        $listing = Listing::factory()->miete()->create();
        ListingPortalPublication::factory()->create(['listing_id' => $listing->id, 'portal_id' => 'a', 'portal_name' => 'Portal A', 'status' => PortalStatus::ManuelleFreigabeErforderlich]);
        ListingPortalPublication::factory()->create(['listing_id' => $listing->id, 'portal_id' => 'b', 'portal_name' => 'Portal B', 'status' => PortalStatus::DeaktivierungAngefordert]);
        ListingPortalPublication::factory()->create(['listing_id' => $listing->id, 'portal_id' => 'c', 'portal_name' => 'Portal C', 'status' => PortalStatus::DeaktivierungBestaetigt]);

        return $listing;
    }

    public function test_portal_summary_text_zeigt_deutsche_label_statt_roher_enum_werte(): void
    {
        $listing = $this->listingMitNeuenPortalstatus();

        $text = PortalSummary::text($listing->fresh(['portalPublications']));

        self::assertStringNotContainsString('manuelle_freigabe_erforderlich', $text);
        self::assertStringNotContainsString('deaktivierung_angefordert', $text);
        self::assertStringNotContainsString('deaktivierung_bestaetigt', $text);
        self::assertStringContainsString(PortalStatus::ManuelleFreigabeErforderlich->label(), $text);
        self::assertStringContainsString(PortalStatus::DeaktivierungAngefordert->label(), $text);
        self::assertStringContainsString(PortalStatus::DeaktivierungBestaetigt->label(), $text);
    }

    public function test_portal_summary_badge_class_kennt_die_drei_neuen_status(): void
    {
        $manuell = Listing::factory()->create();
        ListingPortalPublication::factory()->create(['listing_id' => $manuell->id, 'status' => PortalStatus::ManuelleFreigabeErforderlich]);
        self::assertSame('badge-warning', PortalSummary::badgeClass($manuell->fresh(['portalPublications'])));

        $deaktiviertBestaetigt = Listing::factory()->create();
        ListingPortalPublication::factory()->create(['listing_id' => $deaktiviertBestaetigt->id, 'status' => PortalStatus::DeaktivierungBestaetigt]);
        self::assertSame('badge-neutral', PortalSummary::badgeClass($deaktiviertBestaetigt->fresh(['portalPublications'])));
    }

    public function test_die_uebersicht_zeigt_keine_rohen_statuswerte(): void
    {
        $user = User::factory()->create();
        $this->listingMitNeuenPortalstatus();

        $seite = $this->actingAs($user)->get(route('app.listings.index'));

        $seite->assertOk();
        $seite->assertDontSee('manuelle_freigabe_erforderlich');
        $seite->assertSee('1 '.PortalStatus::ManuelleFreigabeErforderlich->label());
    }

    public function test_die_detailseite_zeigt_keine_rohen_statuswerte(): void
    {
        $user = User::factory()->create();
        $listing = $this->listingMitNeuenPortalstatus();

        $seite = $this->actingAs($user)->get(route('app.listings.show', $listing));

        $seite->assertOk();
        $seite->assertDontSee('manuelle_freigabe_erforderlich');
        $seite->assertSee(PortalStatus::ManuelleFreigabeErforderlich->label());
    }

    public function test_die_pruefseite_zeigt_keine_rohen_statuswerte(): void
    {
        $user = User::factory()->create();
        $listing = $this->listingMitNeuenPortalstatus();

        $seite = $this->actingAs($user)->get(route('app.listings.review', $listing));

        $seite->assertOk();
        $seite->assertDontSee('manuelle_freigabe_erforderlich');
        $seite->assertSee(PortalStatus::ManuelleFreigabeErforderlich->label());
    }
}
