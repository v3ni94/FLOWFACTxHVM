<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Settings;

use App\Domain\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SettingsRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_liefert_den_default_wenn_der_schluessel_fehlt(): void
    {
        $repo = new SettingsRepository;

        $this->assertSame('fallback', $repo->get('unbekannt.schluessel', 'fallback'));
    }

    public function test_set_und_get_geben_den_gleichen_wert_zurueck(): void
    {
        $repo = new SettingsRepository;

        $repo->set('firma.name', 'Hausverwaltung Müller GmbH');

        $this->assertSame('Hausverwaltung Müller GmbH', $repo->get('firma.name'));
    }

    public function test_set_speichert_auch_strukturierte_werte(): void
    {
        $repo = new SettingsRepository;

        $repo->set('flowfact.schema_miete', ['a', 'b']);

        $this->assertSame(['a', 'b'], $repo->get('flowfact.schema_miete'));
    }

    public function test_ein_geheimnis_wird_nicht_im_klartext_gespeichert(): void
    {
        $repo = new SettingsRepository;
        $geheimnis = 'ff-token-abcdefghijklmnop';

        $repo->setSecret('flowfact.api_token', $geheimnis);

        $rohwert = DB::table('settings')->where('key', 'flowfact.api_token')->value('value');

        $this->assertNotNull($rohwert);
        $this->assertStringNotContainsString($geheimnis, $rohwert);
        $this->assertSame($geheimnis, $repo->getSecret('flowfact.api_token'));
    }

    public function test_has_secret_unterscheidet_gesetzt_und_ungesetzt(): void
    {
        $repo = new SettingsRepository;

        $this->assertFalse($repo->hasSecret('flowfact.api_token'));

        $repo->setSecret('flowfact.api_token', 'geheim');

        $this->assertTrue($repo->hasSecret('flowfact.api_token'));
    }

    public function test_forget_entfernt_den_schluessel(): void
    {
        $repo = new SettingsRepository;
        $repo->set('firma.name', 'Test GmbH');

        $repo->forget('firma.name');

        $this->assertNull($repo->get('firma.name'));
    }

    public function test_all_liefert_nur_schluessel_mit_dem_praefix(): void
    {
        $repo = new SettingsRepository;
        $repo->set('firma.name', 'Hausverwaltung Müller GmbH');
        $repo->set('firma.ort', 'Monheim am Rhein');
        $repo->set('ki.provider', 'fake');

        $ergebnis = $repo->all('firma.');

        $this->assertArrayHasKey('firma.name', $ergebnis);
        $this->assertArrayHasKey('firma.ort', $ergebnis);
        $this->assertArrayNotHasKey('ki.provider', $ergebnis);
    }
}
