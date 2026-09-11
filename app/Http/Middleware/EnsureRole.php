<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prüft, ob der angemeldete Benutzer eine bestimmte Rolle besitzt.
 *
 * Verwendung: EnsureRole::class.':admin'
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $user = $request->user();

        if ($user === null || $user->role !== UserRole::from($role)) {
            abort(403);
        }

        return $next($request);
    }
}
