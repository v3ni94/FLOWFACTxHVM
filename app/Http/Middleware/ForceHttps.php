<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Erzwingt HTTPS in der Produktion.
 *
 * Architektur Abschnitt 5: HTTPS und HSTS. Kanonische Produktiv-URL ist
 * https://flowfact.muellerhv.de.
 *
 * In der Produktion werden zwei Dinge sichergestellt:
 *
 *  1. Alle von Laravel erzeugten Links und signierten URLs (Medienauslieferung,
 *     ADR-012) tragen das Schema https, auch wenn der IONOS-Proxy die Anfrage
 *     unverschlüsselt weiterleitet. Sonst würden signierte Downloadlinks mit
 *     falschem Schema signiert und beim Abruf verworfen.
 *  2. Eine unverschlüsselte GET- oder HEAD-Anfrage wird dauerhaft auf HTTPS
 *     umgeleitet. Andere Verfahren werden nicht umgeleitet, weil ein Redirect
 *     die Nutzlast verwerfen würde. Sie erhalten 403 mit einem verständlichen
 *     Hinweis.
 *
 * Außerhalb der Produktion greift die Middleware nicht, damit die lokale
 * Entwicklung und die Testsuite ohne Zertifikat arbeiten können.
 */
class ForceHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->environment('production')) {
            return $next($request);
        }

        URL::forceScheme('https');

        if ($request->isSecure()) {
            return $next($request);
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return redirect()->to(
                'https://'.$request->getHost().$request->getRequestUri(),
                301
            );
        }

        abort(403, 'Diese Verbindung ist nicht verschlüsselt. Bitte rufen Sie die Seite über https auf.');
    }
}
