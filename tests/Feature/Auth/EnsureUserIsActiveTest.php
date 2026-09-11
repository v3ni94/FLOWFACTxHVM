<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Prüfbericht 2026-09-11, Befund 11: Ein deaktivierter Benutzer mit
 * gesetztem "Angemeldet bleiben"-Cookie geriet in eine Umleitungsschleife,
 * weil EnsureUserIsActive nur die Sitzung, nicht aber den Remember-Cookie
 * entwertete. Portiert aus
 * Poc08SecurityTest::test_deaktivierter_benutzer_mit_remember_cookie_landet_in_umleitungsschleife
 * mit umgekehrter Erwartung: der Benutzer landet auf /login, sieht die
 * Meldung und bleibt dort, auch bei einem erneuten Versuch mit dem alten
 * Cookie.
 */
final class EnsureUserIsActiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_ein_deaktivierter_benutzer_mit_remember_cookie_landet_dauerhaft_auf_login(): void
    {
        $user = User::factory()->create(['remember_token' => Str::random(60)]);
        $user->update(['is_active' => false]);

        $recallerName = Auth::guard('web')->getRecallerName();
        $recallerValue = $user->id.'|'.$user->remember_token.'|'.$user->password;

        $erste = $this->withCookie($recallerName, $recallerValue)->get('/app/dashboard');
        $erste->assertRedirect(route('login'));
        $erste->assertSessionHas('error', 'Ihr Konto ist deaktiviert.');
        $this->assertGuest();

        // Das Remember-Cookie darf nach dem Logout in der Middleware nicht
        // mehr gültig sein: /login meldet den Benutzer nicht erneut an.
        $zweite = $this->withCookie($recallerName, $recallerValue)->get('/login');
        $zweite->assertOk();
        $this->assertGuest();

        $dritte = $this->withCookie($recallerName, $recallerValue)->get('/app/dashboard');
        $dritte->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_ein_aktiver_benutzer_mit_remember_cookie_bleibt_angemeldet(): void
    {
        $user = User::factory()->create(['remember_token' => Str::random(60)]);

        $recallerName = Auth::guard('web')->getRecallerName();
        $recallerValue = $user->id.'|'.$user->remember_token.'|'.$user->password;

        $response = $this->withCookie($recallerName, $recallerValue)->get('/app/dashboard');

        $response->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
