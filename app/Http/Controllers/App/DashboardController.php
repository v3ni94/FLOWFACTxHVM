<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\ListingStatus;
use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Übersicht mit Kennzahlen, zuletzt geänderten Objekten und einem ehrlichen
 * Hinweis, wenn sich die Hintergrundverarbeitung nicht meldet (ADR-006,
 * Architektur Abschnitt 8).
 */
class DashboardController extends Controller
{
    private const int SCHEDULER_WARNSCHWELLE_MINUTEN = 15;

    public function index(Request $request): View
    {
        $kennzahlen = [
            'entwuerfe' => Listing::query()->where('status', ListingStatus::Entwurf->value)->count(),
            'bereit' => Listing::query()->where('status', ListingStatus::Bereit->value)->count(),
            'veroeffentlicht' => Listing::query()->where('status', ListingStatus::Veroeffentlicht->value)->count(),
            'uebertragungsfehler' => Listing::query()
                ->whereHas('flowfactLink', fn ($query) => $query->where('sync_status', SyncStatus::Fehlgeschlagen->value))
                ->count(),
        ];

        $letzteObjekte = Listing::query()
            ->with(['flowfactLink'])
            ->orderByDesc('updated_at')
            ->take(10)
            ->get();

        $letzterLauf = Cache::get('scheduler.last_run');
        $verzoegerungMinuten = null;

        if ($letzterLauf !== null) {
            $verzoegerungMinuten = now()->diffInMinutes($letzterLauf, absolute: true);
        }

        return view('app.dashboard', [
            'user' => $request->user(),
            'kennzahlen' => $kennzahlen,
            'letzteObjekte' => $letzteObjekte,
            'schedulerVerzoegerungMinuten' => $verzoegerungMinuten,
            'schedulerWarnung' => $verzoegerungMinuten !== null && $verzoegerungMinuten > self::SCHEDULER_WARNSCHWELLE_MINUTEN,
        ]);
    }
}
