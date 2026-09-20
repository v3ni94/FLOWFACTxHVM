<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Settings\SettingsRepository;
use App\Enums\UserRole;
use App\Flowfact\Mapping\FieldMappingResolver;
use App\Flowfact\Sync\ListingSyncService;
use App\Models\TransferLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FlowfactSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const string TOKEN = 'SECRET-TOKEN-ABC';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

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

        $this->actingAs($mitarbeiter)->get('/admin/flowfact')->assertForbidden();
        $this->actingAs($mitarbeiter)->post('/admin/flowfact/token', ['api_token' => self::TOKEN])->assertForbidden();
        $this->actingAs($mitarbeiter)->post('/admin/flowfact/zuordnung', [])->assertForbidden();

        self::assertFalse($this->settings()->hasSecret('flowfact.api_token'));
    }

    public function test_gast_wird_zur_anmeldung_umgeleitet(): void
    {
        $this->get('/admin/flowfact')->assertRedirect(route('login'));
    }

    public function test_seite_rendert_ohne_token_mit_hinweis_und_ohne_inline_code(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/flowfact');

        $response->assertOk();
        $response->assertSee('FLOWFACT-Anbindung');
        $response->assertSee('Es ist kein API-Token hinterlegt');
        $response->assertSee('Feldzuordnung');
        $response->assertSee('Nebenkosten');
        $response->assertSee('Objektart');
        $this->assertDoesNotMatchRegularExpression('/<[^>]+\sstyle\s*=/i', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $response->getContent());
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=\s*["\']/i', $response->getContent());
    }

    public function test_token_speichern_zeigt_hinterlegt_am_und_rendert_den_token_nie(): void
    {
        Carbon::setTestNow('2026-09-11 14:30:00');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/flowfact/token', ['api_token' => self::TOKEN])
            ->assertRedirect(route('admin.flowfact.edit'));

        self::assertTrue($this->settings()->hasSecret('flowfact.api_token'));
        self::assertSame(self::TOKEN, $this->settings()->getSecret('flowfact.api_token'));

        $response = $this->actingAs($admin)->get('/admin/flowfact');
        $response->assertOk();
        $response->assertSee('hinterlegt am 11.09.2026 14:30');
        $response->assertSee('Token entfernen');
        $response->assertDontSee(self::TOKEN);

        Carbon::setTestNow();
    }

    public function test_token_wird_verschluesselt_gespeichert(): void
    {
        $this->actingAs($this->admin())->post('/admin/flowfact/token', ['api_token' => self::TOKEN]);

        $roh = DB::table('settings')->where('key', 'flowfact.api_token')->value('value');

        self::assertStringNotContainsString(self::TOKEN, (string) $roh);
    }

    public function test_token_entfernen(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/flowfact/token', ['api_token' => self::TOKEN]);

        $this->actingAs($admin)->delete('/admin/flowfact/token')->assertRedirect(route('admin.flowfact.edit'));

        self::assertFalse($this->settings()->hasSecret('flowfact.api_token'));
        self::assertNull($this->settings()->get('flowfact.token_hinterlegt_at'));
    }

    public function test_ungueltiger_token_wird_abgewiesen(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/flowfact')
            ->post('/admin/flowfact/token', ['api_token' => 'kurz'])
            ->assertRedirect('/admin/flowfact')
            ->assertSessionHasErrors('api_token');
    }

    public function test_verbindungstest_erfolg_speichert_ergebnis_ohne_token(): void
    {
        Carbon::setTestNow('2026-09-11 15:00:00');
        $admin = $this->admin();
        $this->settings()->setSecret('flowfact.api_token', self::TOKEN);
        Http::fake(['*/user-service/users/currentUser' => Http::response(['id' => 'u1', 'companyId' => 'company-1', 'type' => 'API', 'loginRelatedMailAddress' => 'api@muellerhv.de'])]);

        $this->actingAs($admin)->post('/admin/flowfact/verbindung-testen')
            ->assertRedirect(route('admin.flowfact.edit'))
            ->assertSessionHas('status');

        Http::assertSent(fn (Request $r): bool => $r->hasHeader('x-ff-version', '2') && $r->hasHeader('x-ff-api-token', self::TOKEN));

        $ergebnis = $this->settings()->get('flowfact.verbindung_ergebnis');
        self::assertSame('Verbunden als api@muellerhv.de (Typ API), Company company-1.', $ergebnis);
        self::assertSame(Carbon::now()->toIso8601String(), $this->settings()->get('flowfact.verbindung_geprueft_at'));

        $seite = $this->actingAs($admin)->get('/admin/flowfact');
        $seite->assertSee('Verbunden als api@muellerhv.de');
        $seite->assertSee('11.09.2026 15:00');
        $seite->assertDontSee(self::TOKEN);

        Carbon::setTestNow();
    }

    public function test_verbindungstest_fehler_speichert_meldung_ohne_token(): void
    {
        $admin = $this->admin();
        $this->settings()->setSecret('flowfact.api_token', self::TOKEN);
        Http::fake(['*' => Http::response(['message' => 'invalid token '.self::TOKEN], 401)]);

        $this->actingAs($admin)->post('/admin/flowfact/verbindung-testen')
            ->assertRedirect(route('admin.flowfact.edit'))
            ->assertSessionHas('error');

        $ergebnis = (string) $this->settings()->get('flowfact.verbindung_ergebnis');
        self::assertStringStartsWith('Fehler:', $ergebnis);
        self::assertStringContainsString('Token ungültig oder Rechte fehlen', $ergebnis);
        self::assertStringNotContainsString(self::TOKEN, $ergebnis);

        $this->actingAs($admin)->get('/admin/flowfact')->assertDontSee(self::TOKEN);
    }

    public function test_diagnose_zeigt_status_und_antwort_je_sonde_ohne_token(): void
    {
        $admin = $this->admin();
        $this->settings()->setSecret('flowfact.api_token', self::TOKEN);
        Http::fake([
            '*/user-service/users/currentUser' => Http::response(['message' => 'forbidden for '.self::TOKEN], 403),
            '*/schema-service/v2/schemas*' => Http::response([['name' => 'estates']]),
            '*' => Http::response(['message' => 'nope'], 403),
        ]);

        $response = $this->actingAs($admin)->post('/admin/flowfact/diagnose');
        $response->assertRedirect(route('admin.flowfact.edit'))->assertSessionHas('diagnose');

        $diagnose = session('diagnose');
        self::assertCount(9, $diagnose);
        self::assertSame(403, $diagnose[0]['status']);
        self::assertStringNotContainsString(self::TOKEN, json_encode($diagnose, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('forbidden', $diagnose[0]['antwort']);
        self::assertSame(200, $diagnose[5]['status']);
        self::assertStringContainsString('estates', $diagnose[5]['antwort']);

        // Vier Übertragungsformen des Tokens werden probiert, danach gilt wieder der Standard.
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('token', self::TOKEN));
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('x-api-key', self::TOKEN));
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://api.production.cloudios.flowfact-prod.cloud/portal-management-service/portals' && $r->hasHeader('x-ff-api-token', self::TOKEN) && ! $r->hasHeader('Authorization'));

        $seite = $this->actingAs($admin)->get('/admin/flowfact');
        $seite->assertOk()->assertSee('Diagnose ausführen')->assertSee('Übertragungsform des Tokens');

        Http::assertSentCount(9);
    }

    public function test_uebertragungsform_des_tokens_ist_einstellbar_und_wirkt_auf_aufrufe(): void
    {
        $admin = $this->admin();
        $this->settings()->setSecret('flowfact.api_token', self::TOKEN);

        $this->actingAs($admin)->post('/admin/flowfact/einstellungen', ['token_header' => 'bearer'])
            ->assertRedirect(route('admin.flowfact.edit'));
        self::assertSame('bearer', $this->settings()->get('flowfact.token_header'));

        Http::fake(['*' => Http::response(['id' => 'u1', 'companyId' => 'c1', 'type' => 'API'])]);
        $this->actingAs($admin)->post('/admin/flowfact/verbindung-testen')->assertSessionHas('status');
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer '.self::TOKEN) && ! $r->hasHeader('x-ff-api-token'));

        $this->actingAs($admin)->post('/admin/flowfact/einstellungen', ['token_header' => 'unbekannt'])
            ->assertSessionHasErrors('token_header');
    }

    public function test_diagnose_ohne_token_ruft_nichts_auf(): void
    {
        Http::fake();

        $this->actingAs($this->admin())->post('/admin/flowfact/diagnose')->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_verbindungstest_ohne_token_liefert_fehlermeldung_ohne_aufruf(): void
    {
        Http::fake();

        $this->actingAs($this->admin())->post('/admin/flowfact/verbindung-testen')->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_einstellungen_speichern(): void
    {
        $this->actingAs($this->admin())->post('/admin/flowfact/einstellungen', [
            'company_id' => 'company-1',
            'schema_miete' => 'wohnung_miete',
            'schema_kauf' => '',
        ])->assertRedirect(route('admin.flowfact.edit'));

        self::assertSame('company-1', $this->settings()->get('flowfact.company_id'));
        self::assertSame('wohnung_miete', $this->settings()->get('flowfact.schema_miete'));
        self::assertNull($this->settings()->get('flowfact.schema_kauf'));
    }

    /**
     * Prüfbericht 2026-09-12, Befund 14: Konfliktverhalten und Löschsemantik
     * sind im Adminbereich einstellbar, nicht nur per Datenbank.
     */
    public function test_konfliktverhalten_und_loeschsemantik_sind_im_adminbereich_einstellbar(): void
    {
        $admin = $this->admin();

        $seite = $this->actingAs($admin)->get('/admin/flowfact');
        $seite->assertOk();
        $seite->assertSee('Konfliktverhalten');
        $seite->assertSee('Geleerte Felder in FLOWFACT löschen');
        $seite->assertSee('name="konfliktverhalten"', false);
        $seite->assertSee('name="leere_felder_loeschen"', false);

        $this->actingAs($admin)->post('/admin/flowfact/einstellungen', [
            'company_id' => '',
            'schema_miete' => 'wohnung_miete',
            'schema_kauf' => '',
            'konfliktverhalten' => 'ueberschreiben',
            'leere_felder_loeschen' => 'aus',
        ])->assertRedirect(route('admin.flowfact.edit'))->assertSessionHas('status');

        self::assertSame('ueberschreiben', $this->settings()->get(ListingSyncService::KONFLIKTVERHALTEN));
        self::assertFalse($this->settings()->get(ListingSyncService::LEERE_FELDER_LOESCHEN));
        self::assertSame(ListingSyncService::KONFLIKT_UEBERSCHREIBEN, app(ListingSyncService::class)->konfliktverhalten());
        self::assertFalse(app(ListingSyncService::class)->leereFelderLoeschen());

        $seite = $this->actingAs($admin)->get('/admin/flowfact');
        $seite->assertSee('value="ueberschreiben" selected', false);
        $seite->assertSee('value="aus" selected', false);

        $this->actingAs($admin)->post('/admin/flowfact/einstellungen', [
            'schema_miete' => 'wohnung_miete',
            'konfliktverhalten' => 'abbrechen',
            'leere_felder_loeschen' => 'an',
        ])->assertRedirect(route('admin.flowfact.edit'));

        self::assertSame('abbrechen', $this->settings()->get(ListingSyncService::KONFLIKTVERHALTEN));
        self::assertTrue($this->settings()->get(ListingSyncService::LEERE_FELDER_LOESCHEN));
        self::assertSame(ListingSyncService::KONFLIKT_ABBRECHEN, app(ListingSyncService::class)->konfliktverhalten());
        self::assertTrue(app(ListingSyncService::class)->leereFelderLoeschen());
    }

    public function test_ungueltige_werte_fuer_konfliktverhalten_und_loeschsemantik_werden_abgewiesen(): void
    {
        $this->settings()->set(ListingSyncService::KONFLIKTVERHALTEN, 'abbrechen');

        $this->actingAs($this->admin())
            ->from('/admin/flowfact')
            ->post('/admin/flowfact/einstellungen', ['konfliktverhalten' => 'ignorieren', 'leere_felder_loeschen' => 'vielleicht'])
            ->assertRedirect('/admin/flowfact')
            ->assertSessionHasErrors(['konfliktverhalten', 'leere_felder_loeschen']);

        self::assertSame('abbrechen', $this->settings()->get(ListingSyncService::KONFLIKTVERHALTEN));
        self::assertNull($this->settings()->get(ListingSyncService::LEERE_FELDER_LOESCHEN));
    }

    public function test_schemata_laden_speichert_liste_und_cache(): void
    {
        $admin = $this->admin();
        $this->settings()->setSecret('flowfact.api_token', self::TOKEN);
        Http::fake([
            '*/schema-service/v2/schemas?*' => Http::response(['entries' => [['name' => 'wohnung_miete', 'captions' => ['de' => 'Wohnung Miete']]], 'totalCount' => 1]),
            '*/schema-service/v2/schemas/wohnung_miete*' => Http::response(['name' => 'wohnung_miete', 'properties' => ['headline' => ['type' => 'TEXT', 'captions' => ['de' => 'Überschrift']]]]),
        ]);

        $this->actingAs($admin)->post('/admin/flowfact/schemata-laden')->assertSessionHas('status');

        self::assertSame([['name' => 'wohnung_miete', 'caption' => 'Wohnung Miete']], $this->settings()->get('flowfact.schemata'));
        self::assertSame('TEXT', $this->settings()->get('flowfact.schema_cache_wohnung_miete')['properties']['headline']['type']);

        $this->settings()->set('flowfact.schema_miete', 'wohnung_miete');
        $seite = $this->actingAs($admin)->get('/admin/flowfact');
        $seite->assertOk();
        $seite->assertSee('Wohnung Miete (wohnung_miete)');
        $seite->assertSee('headline (TEXT, Überschrift)');
    }

    public function test_feld_und_codezuordnung_speichern_nur_abweichungen(): void
    {
        $this->actingAs($this->admin())->post('/admin/flowfact/zuordnung', [
            'felder' => [
                'titel' => 'headline',              // Standard, wird nicht gespeichert
                'beschreibung_objekt' => 'description',
                'kaltmiete_cent' => '',            // Standard abgeschaltet
                'nebenkosten_cent' => '',          // ohne Standard, bleibt weg
                'eigentuemer_name' => 'owner',      // kein Positivlistenfeld, wird ignoriert
            ],
            'codes' => [
                'objektart.stellplatz' => '09STELL',
                'zustand.gepflegt' => '07',        // Standard
                'zustand.saniert' => '',           // Vorschlag abgeschaltet
            ],
        ])->assertRedirect(route('admin.flowfact.edit'));

        self::assertSame([
            'beschreibung_objekt' => 'description',
            'kaltmiete_cent' => null,
        ], $this->settings()->get(FieldMappingResolver::FELDZUORDNUNG));

        self::assertSame([
            'objektart.stellplatz' => '09STELL',
            'zustand.saniert' => null,
        ], $this->settings()->get(FieldMappingResolver::CODEZUORDNUNG));

        $resolver = app(FieldMappingResolver::class);
        self::assertSame('description', $resolver->zielfeld('beschreibung_objekt'));
        self::assertNull($resolver->zielfeld('kaltmiete_cent'));
        self::assertNull($resolver->zielfeld('eigentuemer_name'));
        self::assertSame('09STELL', $resolver->code('objektart', 'stellplatz'));
        self::assertNull($resolver->code('zustand', 'saniert'));
    }

    public function test_ungueltige_zuordnungswerte_werden_abgewiesen(): void
    {
        $this->actingAs($this->admin())
            ->from('/admin/flowfact')
            ->post('/admin/flowfact/zuordnung', ['felder' => ['titel' => 'head line;drop']])
            ->assertRedirect('/admin/flowfact')
            ->assertSessionHasErrors('felder.titel');
    }

    public function test_protokolltabelle_zeigt_die_letzten_eintraege(): void
    {
        TransferLog::factory()->count(3)->create(['listing_id' => null, 'user_id' => null, 'aktion' => 'entity-service POST /schemas/{schema}']);

        $response = $this->actingAs($this->admin())->get('/admin/flowfact');

        $response->assertOk();
        $response->assertSee('entity-service POST /schemas/{schema}');
        $response->assertSee('Übertragungsprotokoll');
    }
}
