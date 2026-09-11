<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Settings\SettingsRepository;
use App\Enums\UserRole;
use App\Http\Controllers\Admin\KiSettingsController;
use App\Models\KiUsage;
use App\Models\User;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\TestCase;

final class KiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const string API_KEY = 'sk-test-KENNUNG-1234567890';

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function settings(): SettingsRepository
    {
        return app(SettingsRepository::class);
    }

    public function test_mitarbeiter_erhaelt_403(): void
    {
        $mitarbeiter = User::factory()->create(['role' => UserRole::Mitarbeiter]);

        $this->actingAs($mitarbeiter)->get('/admin/ki')->assertForbidden();
        $this->actingAs($mitarbeiter)->post('/admin/ki/schluessel', ['api_key' => self::API_KEY])->assertForbidden();
        $this->actingAs($mitarbeiter)->post('/admin/ki/einstellungen', ['provider' => 'fake', 'modell' => 'claude-opus-5'])->assertForbidden();

        self::assertFalse($this->settings()->hasSecret('ki.api_key'));
    }

    public function test_gast_wird_zur_anmeldung_umgeleitet(): void
    {
        $this->get('/admin/ki')->assertRedirect(route('login'));
    }

    public function test_seite_rendert_ohne_schluessel_mit_hinweis_und_ohne_inline_code(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/ki');

        $response->assertOk();
        $response->assertSee('KI-Texte');
        $response->assertSee('Es ist kein API-Schlüssel hinterlegt');
        $response->assertSee('Anbieter und Modell');
        $this->assertDoesNotMatchRegularExpression('/<[^>]+\sstyle\s*=/i', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $response->getContent());
    }

    public function test_schluessel_speichern_zeigt_hinterlegt_am_und_rendert_ihn_nie(): void
    {
        Carbon::setTestNow('2026-09-11 14:30:00');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/ki/schluessel', ['api_key' => self::API_KEY])
            ->assertRedirect(route('admin.ki.edit'));

        self::assertTrue($this->settings()->hasSecret('ki.api_key'));
        self::assertSame(self::API_KEY, $this->settings()->getSecret('ki.api_key'));

        $response = $this->actingAs($admin)->get('/admin/ki');
        $response->assertOk();
        $response->assertSee('hinterlegt am 11.09.2026 14:30');
        $response->assertSee('Schlüssel entfernen');
        $response->assertDontSee(self::API_KEY);

        Carbon::setTestNow();
    }

    public function test_schluessel_wird_verschluesselt_gespeichert(): void
    {
        $this->actingAs($this->admin())->post('/admin/ki/schluessel', ['api_key' => self::API_KEY]);

        $roh = DB::table('settings')->where('key', 'ki.api_key')->value('value');

        self::assertStringNotContainsString(self::API_KEY, (string) $roh);
    }

    public function test_schluessel_entfernen(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/ki/schluessel', ['api_key' => self::API_KEY]);

        $this->actingAs($admin)->delete('/admin/ki/schluessel')->assertRedirect(route('admin.ki.edit'));

        self::assertFalse($this->settings()->hasSecret('ki.api_key'));
        self::assertNull($this->settings()->get('ki.api_key_hinterlegt_at'));
    }

    public function test_ein_zu_kurzer_schluessel_wird_abgewiesen(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/ki')
            ->post('/admin/ki/schluessel', ['api_key' => 'kurz'])
            ->assertRedirect('/admin/ki')
            ->assertSessionHasErrors('api_key');
    }

    public function test_anbieter_und_modell_speichern(): void
    {
        $this->actingAs($this->admin())->post('/admin/ki/einstellungen', [
            'provider' => 'anthropic',
            'modell' => 'claude-sonnet-5',
        ])->assertRedirect(route('admin.ki.edit'));

        self::assertSame('anthropic', $this->settings()->get('ki.provider'));
        self::assertSame('claude-sonnet-5', $this->settings()->get('ki.modell'));
    }

    public function test_ein_unbekanntes_modell_wird_abgewiesen(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/ki')
            ->post('/admin/ki/einstellungen', ['provider' => 'anthropic', 'modell' => 'unbekanntes-modell'])
            ->assertRedirect('/admin/ki')
            ->assertSessionHasErrors('modell');
    }

    public function test_verbindungstest_ohne_schluessel_liefert_fehlermeldung(): void
    {
        $this->actingAs($this->admin())->post('/admin/ki/verbindung-testen')
            ->assertRedirect(route('admin.ki.edit'))
            ->assertSessionHas('error');

        self::assertNull($this->settings()->get(KiSettingsController::VERBINDUNG_GEPRUEFT_AT));
    }

    public function test_verbindungstest_erfolg_speichert_ergebnis_ohne_schluessel(): void
    {
        Carbon::setTestNow('2026-09-11 15:00:00');
        $admin = $this->admin();
        $this->settings()->setSecret('ki.api_key', self::API_KEY);
        $this->settings()->set('ki.modell', 'claude-opus-5');

        $this->app->instance(ClientInterface::class, $this->fakeHttpClient(new Response(
            200,
            ['Content-Type' => 'application/json'],
            (string) json_encode([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-opus-5',
                'container' => null,
                'content' => [['type' => 'text', 'text' => 'OK', 'citations' => null]],
                'stop_reason' => 'end_turn',
                'stop_sequence' => null,
                'usage' => [
                    'input_tokens' => 12,
                    'output_tokens' => 3,
                    'cache_creation_input_tokens' => null,
                    'cache_read_input_tokens' => null,
                    'cache_creation' => null,
                    'server_tool_use' => null,
                    'service_tier' => null,
                    'inference_geo' => null,
                    'output_tokens_details' => null,
                ],
            ]),
        )));

        $this->actingAs($admin)->post('/admin/ki/verbindung-testen')
            ->assertRedirect(route('admin.ki.edit'))
            ->assertSessionHas('status');

        $ergebnis = (string) $this->settings()->get('ki.verbindung_ergebnis');
        self::assertStringContainsString('claude-opus-5', $ergebnis);
        self::assertStringNotContainsString(self::API_KEY, $ergebnis);

        $seite = $this->actingAs($admin)->get('/admin/ki');
        $seite->assertSee('claude-opus-5');
        $seite->assertDontSee(self::API_KEY);

        Carbon::setTestNow();
    }

    public function test_verbindungstest_fehler_speichert_meldung_ohne_schluessel(): void
    {
        $admin = $this->admin();
        $this->settings()->setSecret('ki.api_key', self::API_KEY);

        $this->app->instance(ClientInterface::class, $this->fakeHttpClient(new Response(
            401,
            ['Content-Type' => 'application/json'],
            (string) json_encode([
                'type' => 'error',
                'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key '.self::API_KEY],
            ]),
        )));

        $this->actingAs($admin)->post('/admin/ki/verbindung-testen')
            ->assertRedirect(route('admin.ki.edit'))
            ->assertSessionHas('error');

        $ergebnis = (string) $this->settings()->get('ki.verbindung_ergebnis');
        self::assertStringContainsString('API-Schlüssel ungültig', $ergebnis);
        self::assertStringNotContainsString(self::API_KEY, $ergebnis);

        $this->actingAs($admin)->get('/admin/ki')->assertDontSee(self::API_KEY);
    }

    public function test_verbrauchstabellen_zeigen_die_eintraege(): void
    {
        KiUsage::factory()->create(['modell' => 'claude-opus-5', 'input_tokens' => 1000, 'output_tokens' => 500]);
        KiUsage::factory()->fehlgeschlagen()->create(['modell' => 'claude-opus-5']);

        $response = $this->actingAs($this->admin())->get('/admin/ki');

        $response->assertOk();
        $response->assertSee('Verbrauch der letzten 30 Tage');
        $response->assertSee('Schätzung nach Listenpreis in USD');
        $response->assertSee('claude-opus-5');
        $response->assertSee('Letzte Aufrufe');
    }

    private function fakeHttpClient(ResponseInterface $response): ClientInterface
    {
        return new class($response) implements ClientInterface
        {
            public function __construct(private readonly ResponseInterface $response) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
    }
}
