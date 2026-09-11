<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\UserRole;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Berechtigungen für Objekte (Datenvertrag Abschnitt 6).
 */
final class ListingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_jeder_aktive_benutzer_darf_objekte_anlegen_und_bearbeiten(): void
    {
        $mitarbeiter = User::factory()->create(['role' => UserRole::Mitarbeiter]);
        $listing = Listing::factory()->create();

        $this->assertTrue($mitarbeiter->can('create', Listing::class));
        $this->assertTrue($mitarbeiter->can('update', $listing));
        $this->assertTrue($mitarbeiter->can('publish', $listing));
        $this->assertTrue($mitarbeiter->can('withdraw', $listing));
        $this->assertTrue($mitarbeiter->can('viewAny', Listing::class));
        $this->assertTrue($mitarbeiter->can('view', $listing));
    }

    public function test_ein_admin_darf_jedes_objekt_archivieren(): void
    {
        $admin = User::factory()->admin()->create();
        $ersteller = User::factory()->create();
        $listing = Listing::factory()->create(['erstellt_von_user_id' => $ersteller->id]);

        $this->assertTrue($admin->can('archive', $listing));
    }

    public function test_ein_mitarbeiter_darf_nur_eigene_objekte_archivieren(): void
    {
        $mitarbeiter = User::factory()->create(['role' => UserRole::Mitarbeiter]);
        $andererMitarbeiter = User::factory()->create(['role' => UserRole::Mitarbeiter]);

        $eigenesObjekt = Listing::factory()->create(['erstellt_von_user_id' => $mitarbeiter->id]);
        $fremdesObjekt = Listing::factory()->create(['erstellt_von_user_id' => $andererMitarbeiter->id]);

        $this->assertTrue($mitarbeiter->can('archive', $eigenesObjekt));
        $this->assertFalse($mitarbeiter->can('archive', $fremdesObjekt));
    }

    public function test_niemand_darf_ein_objekt_loeschen(): void
    {
        $admin = User::factory()->admin()->create();
        $listing = Listing::factory()->create(['erstellt_von_user_id' => $admin->id]);

        $this->assertFalse($admin->can('delete', $listing));
    }

    public function test_ein_inaktiver_benutzer_darf_nichts(): void
    {
        $inaktiv = User::factory()->inactive()->create();
        $listing = Listing::factory()->create();

        $this->assertFalse($inaktiv->can('create', Listing::class));
        $this->assertFalse($inaktiv->can('update', $listing));
        $this->assertFalse($inaktiv->can('archive', $listing));
    }
}
