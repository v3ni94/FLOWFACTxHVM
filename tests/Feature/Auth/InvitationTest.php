<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Einladungsablauf (Masterprompt Abschnitt 6, Abgleich B.2/B.3).
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ein_admin_kann_einen_benutzer_per_e_mail_einladen(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post(route('admin.users.invite.store'), [
            'name' => 'Neue Kollegin',
            'email' => 'neue.kollegin@example.test',
            'role' => UserRole::Mitarbeiter->value,
            'darf_veroeffentlichen' => '1',
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('user_invitations', [
            'email' => 'neue.kollegin@example.test',
            'role' => UserRole::Mitarbeiter->value,
            'darf_veroeffentlichen' => true,
            'eingeladen_von_user_id' => $admin->id,
        ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'neue.kollegin@example.test',
        ]);

        Mail::assertSent(UserInvitationMail::class, function (UserInvitationMail $mail): bool {
            return $mail->hasTo('neue.kollegin@example.test');
        });
    }

    public function test_ein_mitarbeiter_kann_niemanden_einladen(): void
    {
        $mitarbeiter = User::factory()->create(['role' => UserRole::Mitarbeiter]);

        $response = $this->actingAs($mitarbeiter)->post(route('admin.users.invite.store'), [
            'name' => 'Neue Kollegin',
            'email' => 'neue.kollegin@example.test',
            'role' => UserRole::Mitarbeiter->value,
        ]);

        $response->assertForbidden();
    }

    public function test_die_einladung_kann_nicht_auf_eine_bereits_vorhandene_e_mail_adresse_ausgestellt_werden(): void
    {
        $admin = User::factory()->admin()->create();
        $vorhanden = User::factory()->create();

        $response = $this->actingAs($admin)->post(route('admin.users.invite.store'), [
            'name' => 'Doppelt',
            'email' => $vorhanden->email,
            'role' => UserRole::Mitarbeiter->value,
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_das_annehmen_einer_gueltigen_einladung_legt_das_konto_an_und_meldet_an(): void
    {
        $admin = User::factory()->admin()->create();
        [$invitation, $token] = UserInvitation::issue(
            'Neue Kollegin',
            'neue.kollegin@example.test',
            UserRole::Mitarbeiter,
            true,
            $admin,
        );

        $signedUrl = URL::signedRoute('invitation.show', ['token' => $token]);
        $zeigen = $this->get($signedUrl);
        $zeigen->assertOk();
        $zeigen->assertSee('Neue Kollegin');

        $response = $this->post(route('invitation.accept', ['token' => $token]), [
            'password' => 'Sicheres-Passwort1',
            'password_confirmation' => 'Sicheres-Passwort1',
        ]);

        $response->assertRedirect(route('app.dashboard'));

        $this->assertDatabaseHas('users', [
            'email' => 'neue.kollegin@example.test',
            'role' => UserRole::Mitarbeiter->value,
            'darf_veroeffentlichen' => true,
        ]);

        $user = User::where('email', 'neue.kollegin@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);

        $invitation->refresh();
        $this->assertNotNull($invitation->accepted_at);
    }

    public function test_eine_bereits_verwendete_einladung_kann_nicht_erneut_verwendet_werden(): void
    {
        $admin = User::factory()->admin()->create();
        [$invitation, $token] = UserInvitation::issue('X', 'einmalig@example.test', UserRole::Mitarbeiter, false, $admin);

        $this->post(route('invitation.accept', ['token' => $token]), [
            'password' => 'Sicheres-Passwort1',
            'password_confirmation' => 'Sicheres-Passwort1',
        ]);

        $anzahlNachErsterAnnahme = User::where('email', 'einmalig@example.test')->count();
        $this->assertSame(1, $anzahlNachErsterAnnahme);

        // Die erste Annahme hat innerhalb dieser Testmethode real angemeldet
        // (Sitzung, nicht nur actingAs); ohne echtes Abmelden würde der
        // zweite Versuch durch die "guest"-Middleware umgeleitet, statt die
        // Token-Prüfung selbst zu erreichen.
        $this->post(route('logout'));

        $zweiterVersuch = $this->post(route('invitation.accept', ['token' => $token]), [
            'password' => 'Anderes-Passwort2',
            'password_confirmation' => 'Anderes-Passwort2',
        ]);

        $zweiterVersuch->assertSessionHas('error');
        $this->assertGuest();
        $this->assertSame(1, User::where('email', 'einmalig@example.test')->count());
    }

    public function test_eine_abgelaufene_einladung_kann_nicht_verwendet_werden(): void
    {
        $admin = User::factory()->admin()->create();
        [$invitation, $token] = UserInvitation::issue('X', 'abgelaufen@example.test', UserRole::Mitarbeiter, false, $admin);
        $invitation->forceFill(['expires_at' => now()->subMinute()])->save();

        $response = $this->post(route('invitation.accept', ['token' => $token]), [
            'password' => 'Sicheres-Passwort1',
            'password_confirmation' => 'Sicheres-Passwort1',
        ]);

        $response->assertSessionHas('error');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'abgelaufen@example.test']);
    }

    public function test_eine_widerrufene_einladung_kann_nicht_verwendet_werden(): void
    {
        $admin = User::factory()->admin()->create();
        [$invitation, $token] = UserInvitation::issue('X', 'widerrufen@example.test', UserRole::Mitarbeiter, false, $admin);

        $this->actingAs($admin)->post(route('admin.users.invitations.revoke', $invitation));

        // actingAs() bleibt für die gesamte Testmethode wirksam; ohne
        // actingAsGuest() würde die folgende Anfrage weiterhin als der
        // angemeldete Admin gesendet und von der "guest"-Middleware
        // umgeleitet, statt die Token-Prüfung selbst zu erreichen.
        $this->actingAsGuest();

        $response = $this->post(route('invitation.accept', ['token' => $token]), [
            'password' => 'Sicheres-Passwort1',
            'password_confirmation' => 'Sicheres-Passwort1',
        ]);

        $response->assertSessionHas('error');
        $this->assertGuest();
    }

    public function test_ein_admin_kann_eine_einladung_erneut_senden_und_der_alte_token_wird_ungueltig(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();
        [$invitation, $alterToken] = UserInvitation::issue('X', 'erneut@example.test', UserRole::Mitarbeiter, false, $admin);

        $response = $this->actingAs($admin)->post(route('admin.users.invitations.resend', $invitation));
        $response->assertRedirect();

        Mail::assertSent(UserInvitationMail::class, 1);

        // Der alte Token ist auf Modellebene sofort ungültig, weil reissue()
        // den Hash ersetzt (App\Models\UserInvitation::reissue()).
        $this->assertNull(UserInvitation::findByToken($alterToken));

        // actingAs() bleibt für die gesamte Testmethode wirksam; ohne
        // actingAsGuest() würde die folgende Anfrage weiterhin als der
        // angemeldete Admin gesendet und von der "guest"-Middleware
        // umgeleitet, statt die Token-Prüfung selbst zu erreichen.
        $this->actingAsGuest();

        $altVersuch = $this->post(route('invitation.accept', ['token' => $alterToken]), [
            'password' => 'Sicheres-Passwort1',
            'password_confirmation' => 'Sicheres-Passwort1',
        ]);

        $altVersuch->assertSessionHas('error');
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'erneut@example.test']);
    }
}
