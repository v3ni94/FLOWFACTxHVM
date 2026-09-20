<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact;

use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Client\Exceptions\FlowfactException;
use App\Flowfact\Client\Exceptions\NotFoundException;
use App\Flowfact\Client\Exceptions\RateLimitException;
use App\Flowfact\Client\Exceptions\ServerException;
use App\Flowfact\Client\Exceptions\TransportException;
use App\Flowfact\Client\Exceptions\ValidationException;
use App\Flowfact\Client\FlowfactClient;
use App\Flowfact\Client\SettingsTokenProvider;
use App\Models\Listing;
use App\Models\TransferLog;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

final class FlowfactClientTest extends FlowfactTestCase
{
    private function client(): FlowfactClient
    {
        return app(FlowfactClient::class);
    }

    public function test_sendet_standardheader_und_basis_url(): void
    {
        $this->hinterlegeToken();
        $this->settings()->set(SettingsTokenProvider::COMPANY_KEY, 'company-42');

        Http::fake([self::BASE.'/user-service/users/currentUser' => Http::response(['id' => 'u1', 'companyId' => 'company-42'])]);

        $antwort = $this->client()->get('user-service', '/users/currentUser', [], [], ['x-ff-version' => '2']);

        self::assertSame('company-42', $antwort['companyId']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === self::BASE.'/user-service/users/currentUser'
                && $request->hasHeader('cognitoToken', $this->fakeCognitoToken)
                && ! $request->hasHeader('x-ff-api-token')
                && $request->hasHeader('Accept-Language', 'de')
                && $request->hasHeader('Accept', 'application/json')
                && $request->hasHeader('x-ff-version', '2')
                && $request->hasHeader('x-ff-company-id', 'company-42');
        });
    }

    public function test_ohne_company_id_wird_der_header_nicht_gesendet(): void
    {
        $this->hinterlegeToken();
        Http::fake([self::BASE.'/*' => Http::response(['ok' => true])]);

        $this->client()->get('user-service', '/users/currentUser');

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('x-ff-company-id') && ! $request->hasHeader('x-ff-version'));
    }

    public function test_pfadparameter_werden_eingesetzt_und_query_uebergeben(): void
    {
        $this->hinterlegeToken();
        Http::fake([self::BASE.'/*' => Http::response(['id' => 'e1'])]);

        $this->client()->get('entity-service', '/schemas/{schema}/entities/{id}', ['schema' => 'wohnung_miete', 'id' => 'e 1'], ['page' => 1]);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'/entity-service/schemas/wohnung_miete/entities/e%201?page=1');
    }

    public function test_ohne_token_wird_nicht_gesendet(): void
    {
        Http::fake();

        $this->expectException(AuthenticationException::class);

        try {
            $this->client()->get('user-service', '/users/currentUser');
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * @return array<string, array{0: int, 1: class-string}>
     */
    public static function statuscodes(): array
    {
        return [
            '401' => [401, AuthenticationException::class],
            '403' => [403, AuthenticationException::class],
            '404' => [404, NotFoundException::class],
            '400' => [400, ValidationException::class],
            '422' => [422, ValidationException::class],
            '429' => [429, RateLimitException::class],
            '500' => [500, ServerException::class],
            '503' => [503, ServerException::class],
            '418' => [418, FlowfactException::class],
        ];
    }

    #[DataProvider('statuscodes')]
    public function test_fehlerklasse_je_statuscode(int $status, string $klasse): void
    {
        $this->hinterlegeToken();
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'Fehlertext'], $status, ['Retry-After' => '17'])]);

        try {
            $this->client()->post('entity-service', '/schemas/{schema}', ['schema' => 'x'], ['headline' => ['values' => ['a']]]);
            self::fail('Es wurde keine Ausnahme ausgelöst.');
        } catch (FlowfactException $exception) {
            self::assertInstanceOf($klasse, $exception);

            if ($exception instanceof RateLimitException) {
                self::assertSame(17, $exception->retryAfterSeconds);
            }

            if ($exception instanceof ValidationException) {
                self::assertSame(['message' => 'Fehlertext'], $exception->body);
            }
        }

        Http::assertSentCount(1);
        self::assertSame(1, TransferLog::query()->count());
        self::assertFalse(TransferLog::query()->first()->erfolgreich);
        self::assertSame($status, TransferLog::query()->first()->http_status);
    }

    public function test_leerer_2xx_koerper_liefert_null(): void
    {
        $this->hinterlegeToken();
        Http::fake([self::BASE.'/*' => Http::response('', 204)]);

        self::assertNull($this->client()->post('portal-management-service', '/publish', [], ['portalId' => 'p']));
        self::assertTrue(TransferLog::query()->first()->erfolgreich);
    }

    public function test_nackter_text_wird_als_string_geliefert(): void
    {
        $this->hinterlegeToken();
        Http::fake([self::BASE.'/*' => Http::response('"neue-id-123"', 200)]);

        self::assertSame('neue-id-123', $this->client()->post('entity-service', '/schemas/{schema}', ['schema' => 'x'], []));
    }

    public function test_get_wird_bei_5xx_genau_einmal_wiederholt(): void
    {
        $this->hinterlegeToken();
        Http::fake([self::BASE.'/*' => Http::sequence()->push(['message' => 'kaputt'], 500)->push(['ok' => true], 200)]);

        self::assertSame(['ok' => true], $this->client()->get('schema-service', '/v2/schemas'));

        Http::assertSentCount(2);
        self::assertSame(2, TransferLog::query()->count());
    }

    public function test_post_wird_bei_5xx_nie_wiederholt(): void
    {
        $this->hinterlegeToken();
        Http::fake([self::BASE.'/*' => Http::sequence()->push(['message' => 'kaputt'], 500)->push(['id' => 'x'], 200)]);

        $this->expectException(ServerException::class);

        try {
            $this->client()->post('entity-service', '/schemas/{schema}', ['schema' => 'x'], []);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_verbindungsfehler_wird_zur_transport_exception_und_protokolliert(): void
    {
        $this->hinterlegeToken();
        $versuche = 0;
        Http::fake(function () use (&$versuche): never {
            $versuche++;

            throw new ConnectionException('cURL error 28: timeout');
        });

        try {
            $this->client()->get('schema-service', '/v2/schemas');
            self::fail('Es wurde keine Ausnahme ausgelöst.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('timeout', $exception->getMessage());
        }

        self::assertSame(2, $versuche, 'GET wird bei Zeitüberschreitung genau einmal wiederholt.');
        self::assertSame(2, TransferLog::query()->count());
        self::assertNull(TransferLog::query()->first()->http_status);
        self::assertFalse(TransferLog::query()->first()->erfolgreich);
    }

    public function test_verbindungsfehler_bei_post_wird_nicht_wiederholt(): void
    {
        $this->hinterlegeToken();
        $versuche = 0;
        Http::fake(function () use (&$versuche): never {
            $versuche++;

            throw new ConnectionException('timeout');
        });

        try {
            $this->client()->post('entity-service', '/schemas/{schema}', ['schema' => 'x'], []);
        } catch (TransportException) {
        }

        self::assertSame(1, $versuche);
    }

    public function test_token_erscheint_nie_im_protokoll_oder_in_fehlermeldungen(): void
    {
        $this->hinterlegeToken();
        Http::fake([self::BASE.'/*' => Http::response(['message' => 'Ungültig: '.self::TOKEN, 'echo' => self::TOKEN], 422)]);

        try {
            $this->client()->post('entity-service', '/schemas/{schema}', ['schema' => 'x'], ['token' => self::TOKEN]);
            self::fail('Es wurde keine Ausnahme ausgelöst.');
        } catch (ValidationException $exception) {
            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            self::assertStringNotContainsString(self::TOKEN, json_encode($exception->body, JSON_THROW_ON_ERROR));
        }

        $log = TransferLog::query()->first();
        $json = json_encode($log->getAttributes(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString(self::TOKEN, $json);
        self::assertStringContainsString('[Token entfernt]', $json);
    }

    public function test_nutzdaten_werden_auf_4096_byte_gekuerzt(): void
    {
        $this->hinterlegeToken();
        $gross = str_repeat('A', 10_000);
        Http::fake([self::BASE.'/*' => Http::response(['text' => $gross])]);

        $this->client()->post('entity-service', '/schemas/{schema}', ['schema' => 'x'], ['text' => $gross]);

        $details = TransferLog::query()->first()->details;

        self::assertLessThanOrEqual(4096 + strlen(' [gekürzt]'), strlen($details['request']));
        self::assertLessThanOrEqual(4096 + strlen(' [gekürzt]'), strlen($details['response']));
        self::assertStringEndsWith('[gekürzt]', $details['response']);
    }

    public function test_listing_und_benutzerkontext_landen_im_protokoll(): void
    {
        $this->hinterlegeToken();
        Http::fake([self::BASE.'/*' => Http::response(['ok' => true])]);

        $listing = Listing::factory()->create();
        $user = User::factory()->create();

        $this->client()->withListing($listing)->withUser($user)->get('entity-service', '/schemas/{schema}/entities/{id}', ['schema' => 's', 'id' => 'e']);

        $log = TransferLog::query()->first();

        self::assertSame($listing->id, $log->listing_id);
        self::assertSame($user->id, $log->user_id);
        self::assertSame('entity-service GET /schemas/{schema}/entities/{id}', $log->aktion);
        self::assertSame('ausgehend', $log->richtung->value);
        self::assertSame('GET', $log->details['methode']);
    }

    public function test_binaerupload_sendet_put_ohne_flowfact_header_und_protokolliert_ohne_signatur(): void
    {
        $this->hinterlegeToken();
        Http::fake(['https://s3.eu-central-1.amazonaws.com/*' => Http::response('', 200)]);

        $this->client()->uploadBinary(self::presignedResponse()['presignedUrl'], 'BINAER', 'image/jpeg');

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PUT'
                && $request->hasHeader('Content-Type', 'image/jpeg')
                && ! $request->hasHeader('x-ff-api-token')
                && ! $request->hasHeader('cognitoToken')
                && $request->body() === 'BINAER';
        });

        $log = TransferLog::query()->first();
        self::assertStringNotContainsString('X-Amz-Signature', $log->details['url']);
        self::assertSame('[Binärdaten, 6 Byte]', $log->details['request']);
    }
}
