<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WartungErsteinrichtungTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // /wartung/ersteinrichtung
    // ------------------------------------------------------------------

    public function test_ersteinrichtung_creates_first_admin_with_token_and_returns_password_once(): void
    {
        config(['deploy.cron_install_token' => 'geheimer-wert']);

        $response = $this->get('/wartung/ersteinrichtung?token=geheimer-wert&email=admin@example.test&name=Erste+Person');

        $response->assertOk()->assertJson(['success' => true]);
        $passwort = $response->json('passwort');
        $this->assertIsString($passwort);
        $this->assertGreaterThanOrEqual(16, strlen($passwort));

        $user = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->assertSame('Erste Person', $user->name);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check($passwort, $user->password));
    }

    public function test_ersteinrichtung_is_locked_once_a_user_exists(): void
    {
        config(['deploy.cron_install_token' => 'geheimer-wert']);
        User::factory()->create();

        $this->get('/wartung/ersteinrichtung?token=geheimer-wert&email=zweite@example.test')
            ->assertStatus(409);

        $this->assertDatabaseMissing('users', ['email' => 'zweite@example.test']);
    }

    public function test_ersteinrichtung_rejects_wrong_or_missing_token_and_invalid_email(): void
    {
        config(['deploy.cron_install_token' => 'geheimer-wert']);

        $this->get('/wartung/ersteinrichtung?token=falsch&email=admin@example.test')->assertStatus(403);
        $this->get('/wartung/ersteinrichtung?email=admin@example.test')->assertStatus(403);
        $this->get('/wartung/ersteinrichtung?token=geheimer-wert&email=keine-adresse')->assertStatus(422);
        $this->assertSame(0, User::query()->count());
    }

    public function test_ersteinrichtung_is_disabled_without_configured_token(): void
    {
        config(['deploy.cron_install_token' => '']);

        $this->get('/wartung/ersteinrichtung?token=x&email=admin@example.test')->assertStatus(404);
    }
}
