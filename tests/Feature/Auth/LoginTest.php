<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Security\Base32;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_mit_korrekten_zugangsdaten_meldet_an_und_setzt_last_login_at(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('geheimes-passwort-1'),
        ]);

        self::assertNull($user->last_login_at);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'geheimes-passwort-1',
        ]);

        $response->assertRedirect(route('app.dashboard'));
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        self::assertNotNull($user->last_login_at);
    }

    public function test_login_mit_falschem_passwort_schlaegt_fehl(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('geheimes-passwort-1'),
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'falsches-passwort',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_ein_deaktivierter_benutzer_erhaelt_dieselbe_meldung_wie_ein_falsches_passwort(): void
    {
        $inactiveUser = User::factory()->inactive()->create([
            'password' => Hash::make('geheimes-passwort-1'),
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => $inactiveUser->email,
            'password' => 'geheimes-passwort-1',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
        $meldungInaktiv = session('errors')->getBag('default')->first('email');

        $activeUser = User::factory()->create([
            'password' => Hash::make('geheimes-passwort-2'),
        ]);

        $response2 = $this->from('/login')->post('/login', [
            'email' => $activeUser->email,
            'password' => 'falsches-passwort',
        ]);

        $response2->assertSessionHasErrors('email');
        $meldungFalsch = session('errors')->getBag('default')->first('email');

        self::assertSame($meldungFalsch, $meldungInaktiv);
    }

    public function test_nach_fuenf_fehlversuchen_wird_die_anmeldung_gedrosselt(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('geheimes-passwort-1'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'email' => $user->email,
                'password' => 'falsch',
            ]);
        }

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'geheimes-passwort-1',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_mit_aktiviertem_zweitfaktor_fuehrt_zur_challenge_statt_direkter_anmeldung(): void
    {
        $secret = Base32::encode('12345678901234567890');

        $user = User::factory()->withTwoFactor($secret)->create([
            'password' => Hash::make('geheimes-passwort-1'),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'geheimes-passwort-1',
        ]);

        $response->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        self::assertSame($user->id, session('auth.two_factor.user_id'));
    }
}
