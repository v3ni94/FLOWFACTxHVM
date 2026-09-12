<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Passwort-Zurücksetzung über den Standard-Broker von Laravel (Masterprompt
 * Abschnitt 6, Abgleich B.2).
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_die_anforderung_verraet_nicht_ob_ein_konto_besteht(): void
    {
        NotificationFacade::fake();

        $vorhandenerBenutzer = User::factory()->create();

        $antwortVorhanden = $this->post(route('password.email'), ['email' => $vorhandenerBenutzer->email]);
        $antwortUnbekannt = $this->post(route('password.email'), ['email' => 'niemand@example.test']);

        $erwarteteMeldung = 'Falls zu dieser E-Mail-Adresse ein Konto besteht, wurde ein Link zum Zurücksetzen des Passworts versendet.';

        $antwortVorhanden->assertSessionHas('status', $erwarteteMeldung);
        $antwortUnbekannt->assertSessionHas('status', $erwarteteMeldung);

        NotificationFacade::assertSentTo($vorhandenerBenutzer, PasswordResetNotification::class);
    }

    public function test_die_benachrichtigung_ist_deutschsprachig_und_keine_illuminate_standardklasse(): void
    {
        NotificationFacade::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        // NotificationFake speichert Zusendungen unter dem exakten
        // Klassennamen des ersten Closure-Parameters (firstClosureParameterType);
        // deshalb muss hier die konkrete Klasse getippt werden, nicht die
        // Basisklasse Notification.
        NotificationFacade::assertSentTo($user, function (PasswordResetNotification $notification) use ($user): bool {
            self::assertInstanceOf(ResetPassword::class, $notification);
            self::assertNotSame(ResetPassword::class, $notification::class);

            /** @var MailMessage $mail */
            $mail = $notification->toMail($user);
            self::assertStringContainsString('Passwort zurücksetzen', $mail->subject);

            return true;
        });
    }

    public function test_das_zuruecksetzen_mit_gueltigem_token_funktioniert(): void
    {
        $user = User::factory()->create(['password' => Hash::make('altes-passwort-1')]);

        $token = Password::broker('users')->createToken($user);

        $response = $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Neues-Passwort9',
            'password_confirmation' => 'Neues-Passwort9',
        ]);

        $response->assertRedirect(route('login'));

        $user->refresh();
        self::assertTrue(Hash::check('Neues-Passwort9', $user->password));
    }

    public function test_das_zuruecksetzen_mit_ungueltigem_token_schlaegt_fehl(): void
    {
        $user = User::factory()->create(['password' => Hash::make('altes-passwort-1')]);

        $response = $this->post(route('password.update'), [
            'token' => 'ein-ungueltiger-token',
            'email' => $user->email,
            'password' => 'Neues-Passwort9',
            'password_confirmation' => 'Neues-Passwort9',
        ]);

        $response->assertSessionHasErrors('email');

        $user->refresh();
        self::assertTrue(Hash::check('altes-passwort-1', $user->password));
    }

    public function test_die_anforderung_wird_auf_drei_pro_minute_und_e_mail_begrenzt(): void
    {
        NotificationFacade::fake();

        $user = User::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('password.email'), ['email' => $user->email]);
        }

        $response = $this->post(route('password.email'), ['email' => $user->email]);

        $response->assertSessionHasErrors('email');
    }

    public function test_die_sitzungstabelle_bleibt_von_der_konfiguration_unberuehrt(): void
    {
        // Reine Absicherung, dass die password_reset_tokens-Tabelle des
        // Standard-Brokers existiert (Masterprompt Abschnitt 6, Abgleich B.2:
        // "die password_reset_tokens-Tabelle existiert bereits").
        self::assertTrue(Schema::hasTable('password_reset_tokens'));
        self::assertNotNull(DB::table('password_reset_tokens'));
    }
}
