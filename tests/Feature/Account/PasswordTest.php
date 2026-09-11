<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_das_passwort_kann_mit_dem_aktuellen_passwort_geaendert_werden(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('altes-passwort-1'),
        ]);

        $response = $this->actingAs($user)->put('/account/password', [
            'current_password' => 'altes-passwort-1',
            'password' => 'Neues-Passwort9',
            'password_confirmation' => 'Neues-Passwort9',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status');

        self::assertTrue(Hash::check('Neues-Passwort9', $user->refresh()->password));
    }

    public function test_das_passwort_kann_nicht_ohne_korrektes_aktuelles_passwort_geaendert_werden(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('altes-passwort-1'),
        ]);

        $response = $this->actingAs($user)->from('/account')->put('/account/password', [
            'current_password' => 'falsch',
            'password' => 'Neues-Passwort9',
            'password_confirmation' => 'Neues-Passwort9',
        ]);

        $response->assertSessionHasErrors('current_password');
        self::assertTrue(Hash::check('altes-passwort-1', $user->refresh()->password));
    }
}
