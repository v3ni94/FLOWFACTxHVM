<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sicherheitsheader für alle Webantworten.
 *
 * Architektur Abschnitt 5, Bedrohung "CSRF und Clickjacking": Sicherheitsheader
 * global, unabhängig von einer bestimmten Route.
 *
 * CONTENT SECURITY POLICY
 *
 * Müller FLOW hat keinen Frontend-Build (ADR-002). Es gibt genau eine eigene
 * CSS-Datei (public/css/flow.css) und eine eigene JavaScript-Datei
 * (public/js/flow.js), beide unter 'self'. Es gibt keine Inline-Skripte,
 * keine Inline-Stile und kein eval, deshalb ist weder ein Nonce noch
 * 'unsafe-eval' oder 'unsafe-inline' nötig. img-src erlaubt zusätzlich data:,
 * weil Objektbilder in der Vorschau als Vorschaugrafiken über signierte
 * Routen ausgeliefert werden können.
 *
 * Die Middleware ist global registriert (bootstrap/app.php), nicht nur in der
 * Gruppe "web", damit auch Antworten außerhalb einer Route (404, 405, die
 * HTTPS-Umleitung) die Sicherheitsheader tragen.
 *
 * HSTS wird ausschließlich über HTTPS und ausschließlich in der Produktion
 * gesetzt. Ein HSTS-Header in der Entwicklung sperrt den Browser dauerhaft
 * auf HTTPS und lässt sich nur schwer zurücknehmen.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $headers = $response->headers;

        $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', $this->permissionsPolicy());
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Personenbezogene Daten dürfen nie in einem geteilten Cache landen.
        if ($request->user() !== null) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        if ($this->hstsErlaubt($request)) {
            $headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
    }

    private function contentSecurityPolicy(): string
    {
        $produktion = app()->environment('production');

        $direktiven = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "script-src 'self'",
            "style-src 'self'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "manifest-src 'self'",
            "media-src 'none'",
            "worker-src 'self'",
            "frame-src 'none'",
        ];

        if ($produktion) {
            $direktiven[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $direktiven);
    }

    /**
     * Alle Browserfunktionen, die die Anwendung nicht benötigt, werden
     * ausdrücklich abgeschaltet.
     */
    private function permissionsPolicy(): string
    {
        return implode(', ', [
            'accelerometer=()',
            'autoplay=()',
            'camera=()',
            'display-capture=()',
            'encrypted-media=()',
            'fullscreen=(self)',
            'geolocation=()',
            'gyroscope=()',
            'magnetometer=()',
            'microphone=()',
            'midi=()',
            'payment=()',
            'usb=()',
            'xr-spatial-tracking=()',
        ]);
    }

    private function hstsErlaubt(Request $request): bool
    {
        return app()->environment('production') && $request->isSecure();
    }
}
