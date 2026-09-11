<?php

declare(strict_types=1);

namespace Tests\Feature\Listing;

use App\Enums\ListingStatus;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArchivePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_ein_mitarbeiter_kann_ein_eigenes_objekt_archivieren(): void
    {
        $mitarbeiter = User::factory()->create();
        $listing = Listing::factory()->create(['erstellt_von_user_id' => $mitarbeiter->id]);

        $response = $this->actingAs($mitarbeiter)->post(route('app.listings.archive', $listing));

        $response->assertRedirect();
        $this->assertSame(ListingStatus::Archiviert, $listing->fresh()->status);
    }

    public function test_ein_mitarbeiter_kann_ein_fremdes_objekt_nicht_archivieren(): void
    {
        $mitarbeiter = User::factory()->create();
        $anderer = User::factory()->create();
        $listing = Listing::factory()->create(['erstellt_von_user_id' => $anderer->id]);

        $response = $this->actingAs($mitarbeiter)->post(route('app.listings.archive', $listing));

        $response->assertForbidden();
        $this->assertNotSame(ListingStatus::Archiviert, $listing->fresh()->status);
    }

    public function test_ein_admin_kann_jedes_objekt_archivieren(): void
    {
        $admin = User::factory()->admin()->create();
        $anderer = User::factory()->create();
        $listing = Listing::factory()->create(['erstellt_von_user_id' => $anderer->id]);

        $response = $this->actingAs($admin)->post(route('app.listings.archive', $listing));

        $response->assertRedirect();
        $this->assertSame(ListingStatus::Archiviert, $listing->fresh()->status);
    }
}
