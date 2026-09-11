<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Meldet einen zwischenzeitlich deaktivierten Benutzer bei der nächsten
 * Anfrage ab. Der Zugriff wird dadurch spätestens beim nächsten Seitenaufruf
 * entzogen, auch wenn die Sitzung noch gültig wäre.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            // Prüfbericht 2026-09-11, Befund 11: Auth::guard('web')->logout()
            // zyklisiert den Remember-Token und löscht das Remember-Cookie.
            // Ohne diesen Aufruf blieb das Cookie gültig und meldete den
            // Benutzer über /login sofort wieder an (Umleitungsschleife).
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('error', 'Ihr Konto ist deaktiviert.');
        }

        return $next($request);
    }
}
