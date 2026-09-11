<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('error', 'Ihr Konto wurde deaktiviert. Bitte wenden Sie sich an die Verwaltung.');
        }

        return $next($request);
    }
}
