<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Settings\SettingsRepository;
use App\Http\Controllers\Admin\DefaultsController;
use App\Http\Controllers\App\Support\InternalNamePattern;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vorgaben im Adminbereich (Masterprompt Abschnitt 18, Masterprompt-Abgleich
 * B.1 Schritt 7).
 */
final class DefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ein_nicht_admin_bekommt_keinen_zugriff(): void
    {
        $mitarbeiter = User::factory()->create();

        $response = $this->actingAs($mitarbeiter)->get(route('admin.defaults.edit'));

        $response->assertForbidden();
    }

    public function test_ein_admin_kann_die_seite_aufrufen(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('admin.defaults.edit'));

        $response->assertOk();
        $response->assertSee('Vorgaben');
    }

    public function test_die_vorgaben_werden_gespeichert(): void
    {
        $admin = User::factory()->admin()->create();
        $ansprechpartner = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('admin.defaults.update'), [
            'land' => 'de',
            'ansprechpartner_user_id' => $ansprechpartner->id,
            'interne_bezeichnung_muster' => '[Objektnummer] - [Ort]',
            'textbausteine_sonstiges' => "Alle Angaben ohne Gewähr.\nÄnderungen vorbehalten.",
        ]);

        $response->assertRedirect(route('admin.defaults.edit'));
        $response->assertSessionHas('status');

        $settings = app(SettingsRepository::class);
        $this->assertSame('DE', $settings->get(DefaultsController::LAND));
        $this->assertSame($ansprechpartner->id, $settings->get(DefaultsController::ANSPRECHPARTNER));
        $this->assertSame('[Objektnummer] - [Ort]', $settings->get(InternalNamePattern::EINSTELLUNGSSCHLUESSEL));
        $this->assertSame(
            ['Alle Angaben ohne Gewähr.', 'Änderungen vorbehalten.'],
            $settings->get(DefaultsController::TEXTBAUSTEINE)
        );
    }

    public function test_die_gespeicherte_interne_bezeichnung_wird_als_vorschlag_in_schritt_7_verwendet(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.defaults.update'), [
            'land' => 'DE',
            'interne_bezeichnung_muster' => '[Objektnummer] - [Ort]',
        ]);

        $listing = Listing::factory()->create(['ort' => 'Erkelenz']);

        $response = $this->actingAs($admin)->get(route('app.listings.step', ['listing' => $listing, 'schritt' => 7]));

        $response->assertOk();
        $response->assertSee($listing->objektnummer.' - Erkelenz', false);
    }
}
