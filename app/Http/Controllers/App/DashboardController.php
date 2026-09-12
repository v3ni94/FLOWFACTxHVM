<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\ListingStatus;
use App\Enums\PortalStatus;
use App\Enums\SyncStatus;
use App\Http\Controllers\Controller;
use App\Models\Listing;
use App\Models\ListingPortalPublication;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Übersicht mit Kennzahlen, den eigenen Entwürfen und einem ehrlichen
 * Hinweis, wenn sich die Hintergrundverarbeitung nicht meldet (ADR-006,
 * Architektur Abschnitt 8, Masterprompt Abschnitt 7).
 */
class DashboardController extends Controller
{
    private const int SCHEDULER_WARNSCHWELLE_MINUTEN = 15;

    public function index(Request $request): View
    {
        $user = $request->user();

        $kennzahlen = [
            'meine_entwuerfe' => Listing::query()
                ->where('bearbeiter_user_id', $user->id)
                ->where('status', ListingStatus::Entwurf->value)
                ->count(),
            'alle_immobilien' => Listing::query()->count(),
            'veroeffentlichungen_aktiv' => ListingPortalPublication::query()
                ->where('status', PortalStatus::Aktiv->value)
                ->count(),
            'uebertragungsfehler' => Listing::query()
                ->where(function ($query): void {
                    $query->whereHas('flowfactLink', fn ($q) => $q->where('sync_status', SyncStatus::Fehlgeschlagen->value))
                        ->orWhereHas('portalPublications', fn ($q) => $q->where('status', PortalStatus::Fehler->value));
                })
                ->count(),
        ];

        $meineEntwuerfe = Listing::query()
            ->with(['flowfactLink'])
            ->where('bearbeiter_user_id', $user->id)
            ->orderByDesc('updated_at')
            ->take(10)
            ->get();

        $letzterLauf = Cache::get('scheduler.last_run');
        $verzoegerungMinuten = null;

        if ($letzterLauf !== null) {
            $verzoegerungMinuten = now()->diffInMinutes($letzterLauf, absolute: true);
        }

        return view('app.dashboard', [
            'user' => $user,
            'kennzahlen' => $kennzahlen,
            'meineEntwuerfe' => $meineEntwuerfe,
            'schedulerVerzoegerungMinuten' => $verzoegerungMinuten,
            'schedulerWarnung' => $verzoegerungMinuten !== null && $verzoegerungMinuten > self::SCHEDULER_WARNSCHWELLE_MINUTEN,
        ]);
    }
}
