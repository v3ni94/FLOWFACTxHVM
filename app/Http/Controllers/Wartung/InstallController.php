<?php

declare(strict_types=1);

namespace App\Http\Controllers\Wartung;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Wartungsendpunkt für Hosting ohne Shellzugang: löst "flow:install" über
 * einen URL-Cronjob oder eine manuelle Anfrage nach dem Deployment aus
 * (docs/betrieb/installation.md).
 *
 * GET oder POST, Token als Kopfzeile X-Cron-Token oder als Parameter
 * ?token=, damit auch URL-Cronjobs und Browserlinks ohne Kopfzeilen
 * funktionieren. Eigener Token (config('deploy.cron_install_token')), verglichen
 * mit hash_equals. Ein leerer Token schaltet den Endpunkt ab.
 */
class InstallController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $configuredToken = (string) config('deploy.cron_install_token', '');

        if ($configuredToken === '') {
            abort(404);
        }

        $providedToken = (string) ($request->header('X-Cron-Token') ?: $request->query('token', ''));

        if ($providedToken === '' || ! hash_equals($configuredToken, $providedToken)) {
            abort(403, 'Ungültiger Token.');
        }

        $exitCode = Artisan::call('flow:install', ['--no-interaction' => true]);

        return response()->json([
            'success' => $exitCode === 0,
            'output' => Artisan::output(),
        ]);
    }
}
