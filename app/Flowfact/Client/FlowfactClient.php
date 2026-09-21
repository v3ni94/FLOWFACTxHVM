<?php

declare(strict_types=1);

namespace App\Flowfact\Client;

use App\Flowfact\Client\Exceptions\AuthenticationException;
use App\Flowfact\Client\Exceptions\FlowfactException;
use App\Flowfact\Client\Exceptions\NotFoundException;
use App\Flowfact\Client\Exceptions\RateLimitException;
use App\Flowfact\Client\Exceptions\ServerException;
use App\Flowfact\Client\Exceptions\TransportException;
use App\Flowfact\Client\Exceptions\ValidationException;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * HTTP-Zugriff auf die FLOWFACT-Services (docs/connector.md Abschnitt 2).
 *
 * Basis-URL plus Service-Name plus Pfad; standardmäßig wird der hinterlegte
 * Zugangsschlüssel zuerst über CognitoTokenCache gegen ein Sitzungstoken
 * getauscht und als Kopfzeile cognitoToken gesendet (flowfact-api.md
 * Abschnitt 3.4). Dazu Accept-Language de, Accept application/json, optional
 * x-ff-company-id und x-ff-version (nur wo dokumentiert). Jeder Aufruf wird
 * über den TransferLogRecorder protokolliert. Wiederholt wird ausschließlich
 * ein lesender GET einmal bei 5xx oder Zeitüberschreitung; schreibende
 * Aufrufe nie, weil eine Wiederholung eine zweite Entität erzeugen könnte.
 * Diese Wiederholung übernimmt der idempotente Ablauf auf Job-Ebene.
 */
final class FlowfactClient
{
    private ?Listing $listing = null;

    private ?User $user = null;

    private ?int $lastStatus = null;

    /** Nur für die Diagnose: erzwingt eine bestimmte Übertragungsform des Tokens. */
    private ?string $tokenHeaderOverride = null;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly TokenProvider $tokenProvider,
        private readonly TransferLogRecorder $recorder,
        private readonly TokenScrubber $scrubber,
        private readonly CognitoTokenCache $cognitoTokenCache,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds = 20,
        private readonly int $uploadTimeoutSeconds = 60,
    ) {}

    public function withListing(?Listing $listing): self
    {
        $client = clone $this;
        $client->listing = $listing;

        return $client;
    }

    public function withUser(?User $user): self
    {
        $client = clone $this;
        $client->user = $user;

        return $client;
    }

    public function lastStatus(): ?int
    {
        return $this->lastStatus;
    }

    /**
     * Diagnose: nächste Aufrufe mit dieser Übertragungsform des Tokens
     * (TokenHeader::*), null stellt die konfigurierte Form wieder her.
     */
    public function usingTokenHeader(?string $form): self
    {
        $this->tokenHeaderOverride = $form;

        return $this;
    }

    public function isConfigured(): bool
    {
        return $this->tokenProvider->token() !== null;
    }

    /**
     * @param  array<string, string|int>  $pathParams
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     */
    public function get(string $service, string $pathTemplate, array $pathParams = [], array $query = [], array $headers = []): mixed
    {
        return $this->request('GET', $service, $pathTemplate, $pathParams, null, $query, $headers);
    }

    /**
     * @param  array<string, string|int>  $pathParams
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     */
    public function post(string $service, string $pathTemplate, array $pathParams = [], mixed $body = null, array $query = [], array $headers = []): mixed
    {
        return $this->request('POST', $service, $pathTemplate, $pathParams, $body, $query, $headers);
    }

    /**
     * @param  array<string, string|int>  $pathParams
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     */
    public function patch(string $service, string $pathTemplate, array $pathParams = [], mixed $body = null, array $query = [], array $headers = []): mixed
    {
        return $this->request('PATCH', $service, $pathTemplate, $pathParams, $body, $query, $headers);
    }

    /**
     * @param  array<string, string|int>  $pathParams
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     */
    public function put(string $service, string $pathTemplate, array $pathParams = [], mixed $body = null, array $query = [], array $headers = []): mixed
    {
        return $this->request('PUT', $service, $pathTemplate, $pathParams, $body, $query, $headers);
    }

    /**
     * @param  array<string, string|int>  $pathParams
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     */
    public function delete(string $service, string $pathTemplate, array $pathParams = [], mixed $body = null, array $query = [], array $headers = []): mixed
    {
        return $this->request('DELETE', $service, $pathTemplate, $pathParams, $body, $query, $headers);
    }

    /**
     * Binärupload an die vorsignierte S3-URL (flowfact-api.md Abschnitt 8,
     * Schritt 3). Ohne FLOWFACT-Header: die Signatur steckt in der URL, der
     * Token gehört nicht zu S3. Protokolliert wird die URL ohne Query, weil
     * die Signatur zeitlich begrenzt gültige Zugangsdaten enthält.
     */
    public function uploadBinary(string $presignedUrl, string $content, string $contentType): void
    {
        $this->lastStatus = null;

        $urlOhneQuery = strtok($presignedUrl, '?') ?: $presignedUrl;
        $aktion = 's3 PUT presigned-url';
        $start = hrtime(true);

        try {
            $response = $this->http
                ->timeout($this->uploadTimeoutSeconds)
                ->withBody($content, $contentType)
                ->put($presignedUrl);
        } catch (ConnectionException $exception) {
            $meldung = (string) $this->scrubber->scrub($exception->getMessage());
            $this->recorder->record($aktion, 'PUT', $urlOhneQuery, sprintf('[Binärdaten, %d Byte]', strlen($content)), null, null, $this->dauer($start), false, 'Bildupload: Verbindungsfehler', $this->listing, $this->user, $meldung);

            throw new TransportException('Bildupload fehlgeschlagen: '.$meldung, null, $exception);
        }

        $this->lastStatus = $response->status();
        $this->recorder->record($aktion, 'PUT', $urlOhneQuery, sprintf('[Binärdaten, %d Byte]', strlen($content)), $response->status(), $response->body(), $this->dauer($start), $response->successful(), $response->successful() ? 'Bildupload erfolgreich' : 'Bildupload fehlgeschlagen', $this->listing, $this->user);

        if (! $response->successful()) {
            throw $this->mapException($response, 'Bildupload');
        }
    }

    /**
     * @param  array<string, string|int>  $pathParams
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     */
    private function request(string $method, string $service, string $pathTemplate, array $pathParams, mixed $body, array $query, array $headers): mixed
    {
        $this->lastStatus = null;

        $zugangsschluessel = $this->tokenProvider->token();

        if ($zugangsschluessel === null) {
            throw new AuthenticationException('Kein FLOWFACT-Token hinterlegt.');
        }

        $form = $this->tokenHeaderOverride ?? $this->tokenProvider->tokenHeader();

        try {
            $token = $form === TokenHeader::COGNITO_TOKEN
                ? $this->cognitoTokenCache->token($zugangsschluessel)
                : $zugangsschluessel;
        } catch (FlowfactException $exception) {
            $this->lastStatus = $exception->httpStatus;

            throw $exception;
        }

        $url = rtrim($this->baseUrl, '/').'/'.trim($service, '/').$this->fillPath($pathTemplate, $pathParams);
        $aktion = $service.' '.$method.' '.$pathTemplate;
        $versuche = $method === 'GET' ? 2 : 1;

        for ($versuch = 1; ; $versuch++) {
            $start = hrtime(true);

            try {
                $response = $this->pending($token, $form, $headers)->send($method, $url, $this->options($body, $query));
            } catch (ConnectionException $exception) {
                $meldung = (string) $this->scrubber->scrub($exception->getMessage());
                $this->recorder->record($aktion, $method, $url, $body, null, null, $this->dauer($start), false, 'Verbindungsfehler oder Zeitüberschreitung', $this->listing, $this->user, $meldung);

                if ($versuch < $versuche) {
                    continue;
                }

                throw new TransportException('Verbindung zu FLOWFACT fehlgeschlagen: '.$meldung, null, $exception);
            }

            $this->lastStatus = $response->status();
            $this->recorder->record($aktion, $method, $url, $body, $response->status(), $response->body(), $this->dauer($start), $response->successful(), $this->zusammenfassung($method, $pathTemplate, $response), $this->listing, $this->user);

            if ($response->successful()) {
                return $this->decode($response);
            }

            if ($response->serverError() && $versuch < $versuche) {
                continue;
            }

            throw $this->mapException($response, $service.' '.$pathTemplate);
        }
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function pending(string $token, string $form, array $headers): PendingRequest
    {
        $standard = TokenHeader::headers($form, $token) + [
            'Accept-Language' => 'de',
            'Accept' => 'application/json',
        ];

        $companyId = $this->tokenProvider->companyId();

        if ($companyId !== null) {
            $standard['x-ff-company-id'] = $companyId;
        }

        return $this->http
            ->withHeaders(array_merge($standard, $headers))
            ->timeout($this->timeoutSeconds);
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function options(mixed $body, array $query): array
    {
        $options = [];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($body !== null) {
            $options['json'] = $body;
        }

        return $options;
    }

    /**
     * @param  array<string, string|int>  $params
     */
    private function fillPath(string $template, array $params): string
    {
        $path = $template;

        foreach ($params as $name => $value) {
            $path = str_replace('{'.$name.'}', rawurlencode((string) $value), $path);
        }

        return '/'.ltrim($path, '/');
    }

    /**
     * Leerer 2xx-Körper ist kein Fehler und wird als null geliefert. Nicht
     * dekodierbarer Text (z. B. eine nackte ID) wird als String geliefert.
     */
    private function decode(Response $response): mixed
    {
        $body = trim($response->body());

        if ($body === '') {
            return null;
        }

        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->scrubber->scrub($body);
        }
    }

    private function mapException(Response $response, string $kontext): FlowfactException
    {
        $status = $response->status();
        $detail = $this->fehlerdetail($response);
        $meldung = sprintf('FLOWFACT antwortete mit HTTP %d (%s)%s', $status, $kontext, $detail !== '' ? ': '.$detail : '');

        return match (true) {
            $status === 401, $status === 403 => new AuthenticationException(AuthenticationException::MELDUNG.' (HTTP '.$status.')', $status),
            $status === 404 => new NotFoundException($meldung, $status),
            $status === 400, $status === 422 => new ValidationException($meldung, $status, is_array($response->json()) ? $this->scrubber->scrubArray($response->json()) : null),
            $status === 429 => new RateLimitException($meldung, $this->retryAfter($response)),
            $status >= 500 => new ServerException($meldung, $status),
            default => new FlowfactException($meldung, $status),
        };
    }

    private function fehlerdetail(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            foreach (['message', 'error', 'detail', 'errors'] as $schluessel) {
                if (isset($json[$schluessel])) {
                    $wert = $json[$schluessel];
                    $text = is_string($wert) ? $wert : json_encode($wert, JSON_UNESCAPED_UNICODE);

                    return mb_substr((string) $this->scrubber->scrub((string) $text), 0, 500);
                }
            }
        }

        return mb_substr((string) $this->scrubber->scrub(trim($response->body())), 0, 500);
    }

    private function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        if ($header !== '' && ctype_digit($header)) {
            return max(1, (int) $header);
        }

        return 60;
    }

    private function zusammenfassung(string $method, string $pathTemplate, Response $response): string
    {
        return sprintf('%s %s: HTTP %d', $method, $pathTemplate, $response->status());
    }

    private function dauer(int|float $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1_000_000);
    }
}
