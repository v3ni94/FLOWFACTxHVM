<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Steps;

use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Leitet Anfragen an den Schritt-Controller weiter. Berechtigung wird hier
 * zentral geprüft: Anzeigen verlangt view, Speichern verlangt update.
 */
final class StepDispatcher extends Controller
{
    use AuthorizesRequests;

    public const int ERSTER_SCHRITT = 1;

    public const int LETZTER_SCHRITT = 8;

    public function show(Request $request, Listing $listing, int $schritt): View
    {
        $this->authorize('view', $listing);

        return $this->handler($schritt)->show($request, $listing);
    }

    public function store(Request $request, Listing $listing, int $schritt): RedirectResponse
    {
        $this->authorize('update', $listing);

        return $this->handler($schritt)->store($request, $listing);
    }

    public function autosave(Request $request, Listing $listing, int $schritt): JsonResponse
    {
        $this->authorize('update', $listing);

        return $this->handler($schritt)->autosave($request, $listing);
    }

    public static function handlerClass(int $schritt): string
    {
        return __NAMESPACE__.'\\Schritt'.$schritt.'Controller';
    }

    private function handler(int $schritt): StepHandler
    {
        if ($schritt < self::ERSTER_SCHRITT || $schritt > self::LETZTER_SCHRITT) {
            abort(404);
        }

        $klasse = self::handlerClass($schritt);

        if (! class_exists($klasse)) {
            abort(404, 'Dieser Schritt ist noch nicht verfügbar.');
        }

        $handler = app($klasse);

        if (! $handler instanceof StepHandler) {
            abort(500, 'Schritt-Controller ohne StepHandler-Vertrag.');
        }

        return $handler;
    }
}
