<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Domain\Security\Base32;
use App\Domain\Security\TimeBasedOneTimePassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_die_einrichtung_erzeugt_ein_unbestaetigtes_geheimnis(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/account/two-factor/setup');

        $response->assertRedirect();
        $user->refresh();

        self::assertNotNull($user->two_factor_secret);
        self::assertNull($user->two_factor_confirmed_at);
    }

    public function test_die_bestaetigung_mit_gueltigem_code_aktiviert_2fa_und_zeigt_wiederherstellungscodes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/account/two-factor/setup');

        $user->refresh();
        $code = (new TimeBasedOneTimePassword)->currentCode($user->two_factor_secret);

        $response = $this->actingAs($user)->post('/account/two-factor/confirm', [
            'code' => $code,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('recovery_codes');
        self::assertCount(8, session('recovery_codes'));

        $user->refresh();
        self::assertNotNull($user->two_factor_confirmed_at);
        self::assertTrue($user->hasTwoFactorEnabled());
        self::assertCount(8, $user->two_factor_recovery_codes);
    }

    public function test_die_bestaetigung_mit_falschem_code_schlaegt_fehl(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/account/two-factor/setup');

        $response = $this->actingAs($user)->post('/account/two-factor/confirm', [
            'code' => '000000',
        ]);

        $response->assertSessionHasErrors('code');

        $user->refresh();
        self::assertNull($user->two_factor_confirmed_at);
    }

    public function test_2fa_kann_mit_dem_aktuellen_passwort_deaktiviert_werden(): void
    {
        $secret = Base32::encode('12345678901234567890');

        $user = User::factory()->withTwoFactor($secret)->create([
            'password' => Hash::make('aktuelles-passwort-1'),
        ]);

        $response = $this->actingAs($user)->delete('/account/two-factor', [
            'current_password' => 'aktuelles-passwort-1',
        ]);

        $response->assertRedirect();

        $user->refresh();
        self::assertFalse($user->hasTwoFactorEnabled());
        self::assertNull($user->two_factor_secret);
        self::assertNull($user->two_factor_recovery_codes);
    }

    public function test_2fa_kann_nicht_ohne_korrektes_passwort_deaktiviert_werden(): void
    {
        $secret = Base32::encode('12345678901234567890');

        $user = User::factory()->withTwoFactor($secret)->create([
            'password' => Hash::make('aktuelles-passwort-1'),
        ]);

        $response = $this->actingAs($user)->delete('/account/two-factor', [
            'current_password' => 'falsch',
        ]);

        $response->assertSessionHasErrorsIn('disable', 'current_password');

        self::assertTrue($user->refresh()->hasTwoFactorEnabled());
    }
}
