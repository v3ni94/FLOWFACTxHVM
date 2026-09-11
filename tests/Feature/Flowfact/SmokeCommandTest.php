<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Models\Listing;
use App\Models\ListingInternal;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\Feature\Flowfact\Support\FakeFlowfact;

final class SmokeCommandTest extends FlowfactTestCase
{
    protected function tearDown(): void
    {
        foreach (File::glob(storage_path('logs/flowfact-smoke-*.md')) ?: [] as $datei) {
            File::delete($datei);
        }

        parent::tearDown();
    }

    private function fakeKonto(): FakeFlowfact
    {
        return $this->fake()
            ->on('GET', '#^/user-service/users/currentUser$#', ['id' => 'u1', 'companyId' => 'company-1', 'type' => 'API', 'loginRelatedMailAddress' => 'api@example.test'])
            ->on('GET', '#^/company-service/company/[^/]+$#', ['id' => 'company-1', 'companyName' => 'Hausverwaltung Müller GmbH'])
            ->on('GET', '#^/schema-service/v2/schemas$#', ['entries' => [['name' => self::SCHEMA_MIETE, 'captions' => ['de' => 'Wohnung Miete']]], 'totalCount' => 1])
            ->on('GET', '#^/schema-service/v2/schemas/[^/]+$#', ['name' => self::SCHEMA_MIETE, 'properties' => ['headline' => ['type' => 'TEXT', 'captions' => ['de' => 'Überschrift']]]])
            ->on('POST', '#^/search-service/schemas/[^/]+$#', self::searchResponse([]))
            ->on('POST', '#^/entity-service/schemas/[^/]+$#', self::entityResponse('ent-test'))
            ->on('GET', '#^/entity-service/schemas/[^/]+/entities/[^/]+$#', self::entityResponse('ent-test', ['headline' => ['values' => ['Testobjekt']]]))
            ->on('PATCH', '#^/entity-service/schemas/[^/]+/entities/[^/]+$#', self::entityResponse('ent-test'))
            ->on('DELETE', '#^/entity-service/schemas/[^/]+/entities/[^/]+$#', '')
            ->on('GET', '#^/entity-service/recovery/entities$#', ['entries' => [['entityId' => 'ent-test', 'schemaName' => self::SCHEMA_MIETE]], 'totalCount' => 1])
            ->on('GET', '#^/multimedia-service/albums/schemas/[^/]+$#', self::albumsResponse())
            ->on('GET', '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+/presigned-url$#', self::presignedResponse())
            ->on('PUT', '#^/flowfact-media/upload/#', fn () => Http::response('', 200))
            ->on('POST', '#^/multimedia-service/items/schemas/[^/]+/entities/[^/]+$#', ['multimediaItem' => self::multimediaItem(77, 'test.jpg')])
            ->on('GET', '#^/multimedia-service/items/entities/[^/]+$#', [self::multimediaItem(77, 'test.jpg')])
            ->on('DELETE', '#^/multimedia-service/items/[^/]+$#', '')
            ->on('GET', '#^/portal-management-service/portals$#', self::portalsResponse())
            ->on('GET', '#^/portal-management-service/estates/[^/]+/portals$#', [])
            ->on('POST', '#^/portal-management-service/publish$#', fn () => Http::response(['sollte' => 'nie passieren']))
            ->install();
    }

    public function test_ohne_token_bricht_der_befehl_sauber_ab(): void
    {
        Http::fake();

        $this->artisan('flow:flowfact:smoke')
            ->expectsOutputToContain('Kein FLOWFACT-Token hinterlegt')
            ->assertExitCode(1);

        Http::assertNothingSent();
        self::assertSame([], File::glob(storage_path('logs/flowfact-smoke-*.md')) ?: []);
    }

    public function test_lesender_lauf_fuehrt_die_schritte_1_bis_5_aus_und_schreibt_ein_protokoll(): void
    {
        $this->hinterlegeToken();
        $fake = $this->fakeKonto();

        $this->artisan('flow:flowfact:smoke')
            ->expectsOutputToContain('Schritt 5')
            ->expectsOutputToContain('Alle Schritte erfolgreich.')
            ->assertExitCode(0);

        self::assertCount(5, $fake->calls);
        self::assertSame(0, $fake->count('POST', '#^/entity-service/schemas/[^/]+$#'));
        self::assertTrue($fake->requests('GET', '#^/user-service/users/currentUser$#')[0]->hasHeader('x-ff-version', '2'));

        $dateien = File::glob(storage_path('logs/flowfact-smoke-*.md')) ?: [];
        self::assertCount(1, $dateien);
        $protokoll = File::get($dateien[0]);
        self::assertStringContainsString('| 1 |', $protokoll);
        self::assertStringContainsString('| 5 |', $protokoll);
        self::assertStringNotContainsString('| 6 |', $protokoll);
        self::assertStringNotContainsString(self::TOKEN, $protokoll);
    }

    public function test_write_erzeugt_identifier_mit_test_praefix_und_raeumt_auf_ohne_publish(): void
    {
        $this->hinterlegeToken();
        $fake = $this->fakeKonto();

        $this->artisan('flow:flowfact:smoke', ['--write' => true])
            ->expectsOutputToContain('Schritt 17')
            ->assertExitCode(0);

        $create = $fake->requests('POST', '#^/entity-service/schemas/[^/]+$#');
        self::assertCount(1, $create);
        self::assertMatchesRegularExpression('/^TEST-\d{8}-\d{6}$/', $create[0]->data()['identifier']['values'][0]);
        self::assertTrue($create[0]->hasHeader('x-ff-version', '2'));

        $upload = $fake->requests('PUT', '#^/flowfact-media/upload/#');
        self::assertCount(1, $upload);
        $info = getimagesizefromstring($upload[0]->body());
        self::assertSame([64, 64, IMAGETYPE_JPEG], [$info[0], $info[1], $info[2]]);

        self::assertSame(1, $fake->count('DELETE', '#^/multimedia-service/items/[^/]+$#'));
        self::assertSame(1, $fake->count('DELETE', '#^/entity-service/schemas/[^/]+/entities/[^/]+$#'));
        self::assertSame(0, $fake->count('POST', '#^/portal-management-service/publish$#'));
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/publish'));

        $protokoll = File::get((File::glob(storage_path('logs/flowfact-smoke-*.md')) ?: [])[0]);
        self::assertStringContainsString('| 15 | POST /publish | entfällt |', $protokoll);
        self::assertStringContainsString('| 17 |', $protokoll);
    }

    public function test_publish_kann_nicht_ueber_eine_option_aktiviert_werden(): void
    {
        $this->hinterlegeToken();
        Http::fake();

        $this->expectException(InvalidOptionException::class);

        $this->artisan('flow:flowfact:smoke', ['--publish' => true]);
    }

    public function test_fehler_in_einem_schritt_stoppt_die_folgenden_und_raeumt_auf(): void
    {
        $this->hinterlegeToken();
        // PATCH schlägt fehl: das Testobjekt muss trotzdem gelöscht werden.
        $fake = $this->fakeKonto()
            ->on('PATCH', '#^/entity-service/schemas/[^/]+/entities/[^/]+$#', ['message' => 'kaputt'], 500);

        $this->artisan('flow:flowfact:smoke', ['--write' => true])->assertExitCode(1);

        self::assertSame(1, $fake->count('DELETE', '#^/entity-service/schemas/[^/]+/entities/[^/]+$#'));
        self::assertSame(0, $fake->count('POST', '#^/portal-management-service/publish$#'));
    }

    public function test_interne_markerwerte_erscheinen_nicht_in_der_ausgabe(): void
    {
        $this->hinterlegeToken();
        $listing = Listing::factory()->miete()->create();
        ListingInternal::factory()->create(['listing_id' => $listing->id, 'interne_notizen' => 'INTERN-MARKER-XYZ']);
        $this->fakeKonto();

        $this->artisan('flow:flowfact:smoke', ['--write' => true])
            ->doesntExpectOutputToContain('INTERN-MARKER-XYZ')
            ->doesntExpectOutputToContain(self::TOKEN)
            ->assertExitCode(0);
    }
}
