<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Security\Base32;
use App\Enums\UserRole;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_ein_mitarbeiter_erhaelt_403_auf_der_benutzerverwaltung(): void
    {
        $mitarbeiter = User::factory()->create(['role' => UserRole::Mitarbeiter]);

        $response = $this->actingAs($mitarbeiter)->get('/admin/users');

        $response->assertForbidden();
    }

    public function test_ein_admin_kann_einen_benutzer_anlegen(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Neue Mitarbeiterin',
            'email' => 'neue.mitarbeiterin@example.test',
            'role' => UserRole::Mitarbeiter->value,
            'phone' => '0211 1234567',
            'password' => 'ein-sehr-langes-init-pw',
            'password_confirmation' => 'ein-sehr-langes-init-pw',
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'neue.mitarbeiterin@example.test',
            'role' => UserRole::Mitarbeiter->value,
        ]);
    }

    public function test_ein_admin_kann_einen_benutzer_bearbeiten(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create(['name' => 'Alter Name']);

        $response = $this->actingAs($admin)->put('/admin/users/'.$target->id, [
            'name' => 'Neuer Name',
            'email' => $target->email,
            'role' => UserRole::Mitarbeiter->value,
            'phone' => '0211 7654321',
        ]);

        $response->assertRedirect(route('admin.users.index'));

        self::assertSame('Neuer Name', $target->refresh()->name);
    }

    public function test_ein_admin_kann_einen_anderen_benutzer_deaktivieren(): void
    {
        $admin = User::factory()->admin()->create();
        $target = User::factory()->create();

        $response = $this->actingAs($admin)->post('/admin/users/'.$target->id.'/deactivate');

        $response->assertRedirect();
        self::assertFalse($target->refresh()->is_active);
    }

    public function test_ein_admin_kann_den_zweitfaktor_eines_benutzers_zuruecksetzen(): void
    {
        $admin = User::factory()->admin()->create();

        $secret = Base32::encode('12345678901234567890');
        $target = User::factory()->withTwoFactor($secret)->create();

        $response = $this->actingAs($admin)->post('/admin/users/'.$target->id.'/reset-two-factor');

        $response->assertRedirect();

        $target->refresh();
        self::assertFalse($target->hasTwoFactorEnabled());
        self::assertNull($target->two_factor_secret);
    }

    public function test_der_letzte_aktive_admin_kann_nicht_deaktiviert_werden(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();

        // Der zweite Admin wird zuerst deaktiviert, damit $admin der letzte aktive ist.
        $this->actingAs($admin)->post('/admin/users/'.$otherAdmin->id.'/deactivate');
        self::assertFalse($otherAdmin->refresh()->is_active);

        // $admin ist nun der einzige aktive Admin. Über die Route ist eine
        // Deaktivierung nur noch durch $admin selbst erreichbar; die Policy
        // greift unabhängig von der Selbstdeaktivierungsregel.
        $response = $this->actingAs($admin)->post('/admin/users/'.$admin->id.'/deactivate');

        $response->assertRedirect();
        self::assertTrue($admin->refresh()->is_active);

        // Policy-Prüfung unabhängig von der Selbstdeaktivierungsregel, mit einem
        // anderen Akteur als dem betroffenen letzten Admin.
        self::assertFalse((new UserPolicy)->deactivate($otherAdmin, $admin->fresh()));
    }

    public function test_ein_admin_kann_sich_nicht_selbst_deaktivieren(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/admin/users/'.$admin->id.'/deactivate');

        $response->assertRedirect();
        self::assertTrue($admin->refresh()->is_active);
    }
}
