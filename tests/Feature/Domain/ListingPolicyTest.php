<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\UserRole;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Berechtigungen für Objekte (Masterprompt Abschnitt 6, Abgleich B.3).
 *
 * Ersetzt die frühere, undifferenzierte Fassung: Anlegen, Bearbeiten,
 * Veröffentlichen und Zurückziehen sind nicht mehr jedem aktiven Benutzer
 * gleichermaßen erlaubt, sondern hängen von Rolle, Bearbeiterzuordnung und
 * dem Recht darf_veroeffentlichen ab.
 */
final class ListingPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_ein_admin_darf_alles_ausser_loeschen(): void
    {
        $admin = User::factory()->admin()->create();
        $anderer = User::factory()->create();
        $listing = Listing::factory()->create(['erstellt_von_user_id' => $anderer->id]);

        $this->assertTrue($admin->can('viewAny', Listing::class));
        $this->assertTrue($admin->can('view', $listing));
        $this->assertTrue($admin->can('create', Listing::class));
        $this->assertTrue($admin->can('update', $listing));
        $this->assertTrue($admin->can('publish', $listing));
        $this->assertTrue($admin->can('withdraw', $listing));
        $this->assertTrue($admin->can('archive', $listing));
        $this->assertTrue($admin->can('duplicate', Listing::class));
        $this->assertFalse($admin->can('delete', $listing));
    }

    public function test_ein_leser_darf_nur_sehen(): void
    {
        $leser = User::factory()->create(['role' => UserRole::Leser]);
        $listing = Listing::factory()->create(['erstellt_von_user_id' => $leser->id]);

        $this->assertTrue($leser->can('viewAny', Listing::class));
        $this->assertTrue($leser->can('view', $listing));
        $this->assertFalse($leser->can('create', Listing::class));
        $this->assertFalse($leser->can('update', $listing));
        $this->assertFalse($leser->can('publish', $listing));
        $this->assertFalse($leser->can('withdraw', $listing));
        $this->assertFalse($leser->can('archive', $listing));
        $this->assertFalse($leser->can('duplicate', Listing::class));
    }

    public function test_ein_mitarbeiter_ohne_zuordnung_darf_ein_unzugeordnetes_objekt_bearbeiten(): void
    {
        // Übergangsregel dieser Welle (ListingPolicy-Dokumentation): Solange
        // bearbeiter_user_id leer ist (aktueller Assistent setzt es nicht),
        // bleibt das Objekt für jeden aktiven Mitarbeiter bearbeitbar.
        $mitarbeiter = User::factory()->create(['role' => UserRole::Mitarbeiter]);
        $andererErsteller = User::factory()->create(['role' => UserRole::Mitarbeiter]);

        $listing = Listing::factory()->create([
            'erstellt_von_user_id' => $andererErsteller->id,
            'bearbeiter_user_id' => null,
        ]);

        $this->assertTrue($mitarbeiter->can('create', Listing::class));
        $this->assertTrue($mitarbeiter->can('update', $listing));
    }

    public function test_ein_mitarbeiter_darf_ein_zugeordnetes_fremdes_objekt_nicht_bearbeiten(): void
    {
        $mitarbeiter = User::factory()->create(['role' => UserRole::Mitarbeiter]);
        $bearbeiter = User::factory()->create(['role' => UserRole::Mitarbeiter]);

        $listing = Listing::factory()->create([
            'erstellt_von_user_id' => $bearbeiter->id,
            'bearbeiter_user_id' => $bearbeiter->id,
        ]);

        $this->assertFalse($mitarbeiter->can('update', $listing));
    }

    public function test_ein_mitarbeiter_darf_ein_ihm_zugeordnetes_objekt_bearbeiten(): void
    {
        $bearbeiter = User::factory()->create(['role' => UserRole::Mitarbeiter]);
        $ersteller = User::factory()->create(['role' => UserRole::Mitarbeiter]);

        $listing = Listing::factory()->create([
            'erstellt_von_user_id' => $ersteller->id,
            'bearbeiter_user_id' => $bearbeiter->id,
        ]);

        $this->assertTrue($bearbeiter->can('update', $listing));
    }

    public function test_ein_fuer_alle_freigegebenes_objekt_darf_jeder_mitarbeiter_bearbeiten(): void
    {
        $mitarbeiter = User::factory()->create(['role' => UserRole::Mitarbeiter]);
        $bearbeiter = User::factory()->create(['role' => UserRole::Mitarbeiter]);

        $listing = Listing::factory()->create([
            'bearbeiter_user_id' => $bearbeiter->id,
            'freigegeben_fuer_alle' => true,
        ]);

        $this->assertTrue($mitarbeiter->can('update', $listing));
    }

    public function test_ein_mitarbeiter_ohne_darf_veroeffentlichen_darf_nicht_veroeffentlichen_oder_zurueckziehen(): void
    {
        $mitarbeiter = User::factory()->create([
            'role' => UserRole::Mitarbeiter,
            'darf_veroeffentlichen' => false,
        ]);
        $listing = Listing::factory()->create(['erstellt_von_user_id' => $mitarbeiter->id]);

        $this->assertTrue($mitarbeiter->can('update', $listing));
        $this->assertFalse($mitarbeiter->can('publish', $listing));
        $this->assertFalse($mitarbeiter->can('withdraw', $listing));
    }

    public function test_ein_mitarbeiter_mit_darf_veroeffentlichen_darf_veroeffentlichen(): void
    {
        $mitarbeiter = User::factory()->create([
            'role' => UserRole::Mitarbeiter,
            'darf_veroeffentlichen' => true,
        ]);
        $listing = Listing::factory()->create(['erstellt_von_user_id' => $mitarbeiter->id]);

        $this->assertTrue($mitarbeiter->can('publish', $listing));
        $this->assertTrue($mitarbeiter->can('withdraw', $listing));
    }

    public function test_darf_veroeffentlichen_ohne_bearbeitungsrecht_reicht_nicht(): void
    {
        $mitarbeiter = User::factory()->create([
            'role' => UserRole::Mitarbeiter,
            'darf_veroeffentlichen' => true,
        ]);
        $bearbeiter = User::factory()->create(['role' => UserRole::Mitarbeiter]);

        $listing = Listing::factory()->create([
            'erstellt_von_user_id' => $bearbeiter->id,
            'bearbeiter_user_id' => $bearbeiter->id,
        ]);

        $this->assertFalse($mitarbeiter->can('publish', $listing));
        $this->assertFalse($mitarbeiter->can('withdraw', $listing));
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

    /**
     * Ende-zu-Ende-Nachweis, dass die Policy tatsächlich in der bestehenden
     * Route greift (nicht nur in der isolierten Can-Prüfung oben): Der
     * Assistent (app/Http/Controllers/App/ListingWizardController, in dieser
     * Welle unverändert) prüft die Fähigkeit "update" bereits beim Anzeigen
     * eines Schritts.
     */
    public function test_ein_leser_erhaelt_403_beim_aufruf_eines_assistentenschritts(): void
    {
        $leser = User::factory()->create(['role' => UserRole::Leser]);
        $listing = Listing::factory()->create();

        $response = $this->actingAs($leser)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 1]));

        $response->assertForbidden();
    }

    /**
     * Ende-zu-Ende-Nachweis für die neue Voraussetzung
     * User::kannVeroeffentlichen() beim Zurückziehen (Masterprompt Abschnitt
     * 6, Abgleich B.3): ein Mitarbeiter ohne das Flag darf_veroeffentlichen
     * erhält 403, obwohl er das Objekt bearbeiten dürfte.
     */
    public function test_ein_mitarbeiter_ohne_darf_veroeffentlichen_erhaelt_403_beim_zurueckziehen(): void
    {
        $mitarbeiter = User::factory()->create([
            'role' => UserRole::Mitarbeiter,
            'darf_veroeffentlichen' => false,
        ]);
        $listing = Listing::factory()->create(['erstellt_von_user_id' => $mitarbeiter->id]);

        $response = $this->actingAs($mitarbeiter)->post(route('app.listings.withdraw', $listing));

        $response->assertForbidden();
    }
}
