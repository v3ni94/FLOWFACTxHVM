<?php

declare(strict_types=1);

namespace App\Http\Controllers\Wartung;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Wartungsendpunkt für Hosting ohne Shellzugang: löst "schedule:run" über
 * einen URL-Cronjob aus (docs/betrieb/installation.md).
 *
 * Nur POST, eigener Token je Endpunkt (config('deploy.cron_schedule_token')),
 * verglichen mit hash_equals. Ein leerer Token schaltet den Endpunkt ab.
 */
class ScheduleController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $configuredToken = (string) config('deploy.cron_schedule_token', '');

        if ($configuredToken === '') {
            abort(404);
        }

        $providedToken = (string) $request->header('X-Cron-Token', '');

        if ($providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            abort(403, 'Ungültiger Token.');
        }

        Artisan::call('schedule:run');

        return response()->json([
            'success' => true,
            'output' => Artisan::output(),
        ]);
    }
}
