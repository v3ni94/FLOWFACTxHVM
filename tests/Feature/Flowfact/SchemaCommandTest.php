<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Flowfact\Mapping\FieldMappingResolver;
use Illuminate\Support\Facades\Http;

final class SchemaCommandTest extends FlowfactTestCase
{
    public function test_ohne_token_bricht_der_befehl_ab(): void
    {
        Http::fake();

        $this->artisan('flow:flowfact:schema')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_listet_die_estate_schemata(): void
    {
        $this->hinterlegeToken();
        Http::fake([self::BASE.'/schema-service/v2/schemas*' => Http::response(['entries' => [
            ['name' => self::SCHEMA_MIETE, 'captions' => ['de' => 'Wohnung Miete']],
            ['name' => self::SCHEMA_KAUF, 'captions' => ['de' => 'Haus Kauf']],
        ], 'totalCount' => 2])]);

        $this->artisan('flow:flowfact:schema')
            ->expectsOutputToContain(self::SCHEMA_MIETE)
            ->expectsOutputToContain('Haus Kauf')
            ->assertExitCode(0);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'group=estates'));
    }

    public function test_zeigt_properties_und_zuordnungsstatus_und_fuellt_den_cache(): void
    {
        $this->hinterlegeToken();
        $this->settings()->set(FieldMappingResolver::FELDZUORDNUNG, ['beschreibung_objekt' => 'gibt_es_nicht']);
        Http::fake([self::BASE.'/schema-service/v2/schemas/'.self::SCHEMA_MIETE.'*' => Http::response([
            'name' => self::SCHEMA_MIETE,
            'properties' => [
                'headline' => ['type' => 'TEXT', 'captions' => ['de' => 'Überschrift']],
                'identifier' => ['type' => 'TEXT', 'captions' => ['de' => 'Objektnummer']],
                'rent' => ['type' => 'NUMBER', 'captions' => ['de' => 'Kaltmiete']],
            ],
        ])]);

        $this->artisan('flow:flowfact:schema', ['--schema' => self::SCHEMA_MIETE])
            ->expectsOutputToContain('Überschrift')
            ->expectsOutputToContain('zugeordnet')
            ->expectsOutputToContain('Zuordnung fehlt')
            ->expectsOutputToContain('Zielfeld nicht im Schema')
            ->assertExitCode(0);

        $cache = $this->settings()->get('flowfact.schema_cache_'.self::SCHEMA_MIETE);
        self::assertSame(self::SCHEMA_MIETE, $cache['name']);
        self::assertSame(['type' => 'NUMBER', 'caption' => 'Kaltmiete'], $cache['properties']['rent']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'extensions=all'));
    }
}
