<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Platzhalter-Dashboard. Wird in Phase 3 (Architektur Abschnitt 8) durch die
 * eigentliche Übersicht mit Kennzahlen ersetzt.
 */
class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        return view('app.dashboard', [
            'user' => $request->user(),
        ]);
    }
}
