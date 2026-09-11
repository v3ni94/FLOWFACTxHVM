<?php

declare(strict_types=1);

namespace Tests\Feature\Flowfact\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Kleiner Router für Http::fake(): registriert Antworten je Methode und
 * Pfadmuster. Unbekannte Aufrufe liefern null, sodass
 * Http::preventStrayRequests() sie als Fehler meldet.
 */
final class FakeFlowfact
{
    /** @var list<array{method: string, pattern: string, handler: mixed, status: int}> */
    private array $routes = [];

    /** @var list<array{method: string, path: string, request: Request}> */
    public array $calls = [];

    /**
     * @param  callable|array<mixed>|string|null  $handler  Callable (Request, matches) oder Antwortkörper
     */
    public function on(string $method, string $pattern, callable|array|string|null $handler = null, int $status = 200): self
    {
        $this->routes[] = ['method' => strtoupper($method), 'pattern' => $pattern, 'handler' => $handler, 'status' => $status];

        return $this;
    }

    public function install(): self
    {
        Http::fake(fn (Request $request) => $this->handle($request));

        return $this;
    }

    public function count(string $method, string $pattern): int
    {
        return count(array_filter(
            $this->calls,
            fn (array $call): bool => $call['method'] === strtoupper($method) && preg_match($pattern, $call['path']) === 1,
        ));
    }

    /**
     * @return list<Request>
     */
    public function requests(string $method, string $pattern): array
    {
        return array_values(array_map(
            fn (array $call): Request => $call['request'],
            array_filter(
                $this->calls,
                fn (array $call): bool => $call['method'] === strtoupper($method) && preg_match($pattern, $call['path']) === 1,
            ),
        ));
    }

    private function handle(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $method = strtoupper($request->method());

        // Später registrierte Routen gewinnen, damit Tests einzelne Antworten überschreiben können.
        foreach (array_reverse($this->routes) as $route) {
            if ($route['method'] !== $method || preg_match($route['pattern'], $path, $matches) !== 1) {
                continue;
            }

            $this->calls[] = ['method' => $method, 'path' => $path, 'request' => $request];

            $handler = $route['handler'];

            if (is_callable($handler)) {
                return $handler($request, $matches);
            }

            return Http::response($handler, $route['status']);
        }

        return null;
    }
}
