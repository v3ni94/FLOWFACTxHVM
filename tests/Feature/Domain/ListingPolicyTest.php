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
     * Ende-zu-Ende-Nachweis, dass die Policy tatsächlich in der Route greift
     * (nicht nur in der isolierten Can-Prüfung oben): Der neue Assistent
     * (App\Http\Controllers\App\Steps\StepDispatcher, Masterprompt-Abgleich
     * B.1, Welle 2) prüft "view" beim Anzeigen eines Schritts (jeder aktive
     * Benutzer, auch Leser, darf sehen) und "update" beim Speichern
     * (Formular-POST) und beim Autosave (PATCH); nur dort ist ein Leser
     * ausgeschlossen (B.3: "Objekte sehen: alle").
     */
    public function test_ein_leser_darf_einen_assistentenschritt_ansehen_aber_nicht_speichern(): void
    {
        $leser = User::factory()->create(['role' => UserRole::Leser]);
        $listing = Listing::factory()->create();

        $this->actingAs($leser)
            ->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 1]))
            ->assertOk();

        $this->actingAs($leser)
            ->post(route('app.listings.step.store', ['listing' => $listing, 'schritt' => 1]), ['aktion' => 'speichern'])
            ->assertForbidden();

        $this->actingAs($leser)
            ->patchJson(route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 1]), [])
            ->assertForbidden();
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

    /**
     * Prüfbericht 2026-09-12, Befund 9: ein archiviertes Objekt ist ein
     * eingefrorener Nachweisstand, es gibt keinen Übergang zurück. Weder
     * Admin noch Mitarbeiter dürfen es über den Assistenten ändern.
     */
    public function test_ein_archiviertes_objekt_darf_von_niemandem_bearbeitet_werden(): void
    {
        $admin = User::factory()->admin()->create();
        $mitarbeiter = User::factory()->create();
        $listing = Listing::factory()->archiviert()->create(['erstellt_von_user_id' => $mitarbeiter->id]);

        $this->assertFalse($admin->can('update', $listing));
        $this->assertFalse($mitarbeiter->can('update', $listing));
        $this->assertFalse($admin->can('publish', $listing));
        $this->assertFalse($admin->can('withdraw', $listing));
    }

    public function test_ein_archiviertes_objekt_bleibt_ueber_schritt_formulare_und_autosave_unveraenderbar(): void
    {
        $user = User::factory()->create();
        $listing = Listing::factory()->archiviert()->create(['bearbeiter_user_id' => $user->id, 'erstellt_von_user_id' => $user->id]);

        $this->actingAs($user)
            ->patchJson(route('app.listings.step.autosave', ['listing' => $listing, 'schritt' => 7]), ['titel' => 'Nach Archivierung geändert'])
            ->assertForbidden();

        $this->assertNotSame('Nach Archivierung geändert', $listing->fresh()->titel);

        $this->actingAs($user)
            ->post(route('app.listings.step.store', ['listing' => $listing, 'schritt' => 3]), ['baujahr' => '1999'])
            ->assertForbidden();
    }
}
