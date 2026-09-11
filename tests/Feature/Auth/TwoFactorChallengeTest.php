<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Security\Base32;
use App\Domain\Security\TimeBasedOneTimePassword;
use App\Http\Controllers\Auth\LoginController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorChallengeTest extends TestCase
{
    use RefreshDatabase;

    private const string SECRET_ASCII = '12345678901234567890';

    private function secret(): string
    {
        return Base32::encode(self::SECRET_ASCII);
    }

    private function startPendingLogin(User $user): void
    {
        $this->withSession([
            LoginController::SESSION_PENDING_USER_ID => $user->id,
            LoginController::SESSION_PENDING_REMEMBER => false,
        ]);
    }

    public function test_ein_falscher_code_wird_abgelehnt(): void
    {
        $user = User::factory()->withTwoFactor($this->secret())->create();
        $this->startPendingLogin($user);

        $response = $this->from(route('two-factor.challenge'))->post('/two-factor/challenge', [
            'code' => '000000',
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_ein_gueltiger_totp_code_meldet_an_und_setzt_last_login_at(): void
    {
        $user = User::factory()->withTwoFactor($this->secret())->create();
        $this->startPendingLogin($user);

        $code = (new TimeBasedOneTimePassword)->currentCode($this->secret());

        $response = $this->post('/two-factor/challenge', [
            'code' => $code,
        ]);

        $response->assertRedirect(route('app.dashboard'));
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        self::assertNotNull($user->last_login_at);
        self::assertNull(session(LoginController::SESSION_PENDING_USER_ID));
    }

    public function test_ein_wiederherstellungscode_meldet_an_und_wird_danach_verbraucht(): void
    {
        $klartextCode = 'ABCDE-FGHJK';

        $user = User::factory()->create([
            'two_factor_secret' => $this->secret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [Hash::make($klartextCode)],
        ]);

        $this->startPendingLogin($user);

        $response = $this->post('/two-factor/challenge', [
            'recovery_code' => $klartextCode,
        ]);

        $response->assertRedirect(route('app.dashboard'));
        $this->assertAuthenticatedAs($user);

        $user->refresh();
        self::assertSame([], $user->two_factor_recovery_codes);

        // Ein zweiter Versuch mit demselben Code muss scheitern, da er bereits verbraucht ist.
        auth()->logout();
        $this->startPendingLogin($user);

        $second = $this->post('/two-factor/challenge', [
            'recovery_code' => $klartextCode,
        ]);

        $second->assertSessionHasErrors('code');
    }
}
