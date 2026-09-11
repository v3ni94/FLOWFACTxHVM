<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ForceHttps;
use App\Http\Middleware\SecurityHeaders;
use App\Support\TrustedProxyConfiguration;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/web.php',
            __DIR__.'/../routes/wartung.php',
            __DIR__.'/../routes/flowfact.php',
            __DIR__.'/../routes/ki.php',
        ],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ForceHttps muss vor allem anderen in der Gruppe "web" laufen, damit
        // URL::forceScheme bereits greift, bevor irgendeine URL erzeugt wird
        // (Architektur Abschnitt 5, ADR-012 signierte Medien-URLs).
        $middleware->web(prepend: [ForceHttps::class]);

        // Global, nicht nur in "web": auch 404, 405 und die HTTPS-Umleitung
        // sollen die Sicherheitsheader tragen.
        $middleware->append(SecurityHeaders::class);

        // Wartungsendpunkte werden von Cron-Diensten ohne Sitzung aufgerufen.
        // Die Echtheit wird ausschließlich über den Token-Header geprüft.
        $middleware->validateCsrfTokens(except: [
            'wartung/*',
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        // Ohne konfigurierte Anmeldung (Agent A noch nicht integriert) bleibt
        // die Anwendung auch ohne die Route "login" lauffähig.
        $middleware->redirectGuestsTo(fn () => Route::has('login') ? route('login') : '/');
        $middleware->redirectUsersTo(fn () => Route::has('app.dashboard') ? route('app.dashboard') : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();

// Vertrauenswürdige Proxys (config/deploy.php, TRUSTED_PROXIES) und die
// TrustProxies-Header werden erst gesetzt, sobald die Anwendung gebootet ist,
// damit die vollständige (auch getestete) Konfiguration vorliegt.
$app->booted(function () use ($app): void {
    TrustedProxyConfiguration::apply($app->make('config')->get('deploy.trusted_proxies'));
});

return $app;
