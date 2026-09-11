<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|------------------------------------------------------------------------------
| Scheduler (ADR-006)
|------------------------------------------------------------------------------
|
| Ein einziger Cronjob ruft "php artisan schedule:run" jede Minute auf
| (Architektur Abschnitt 6), alternativ der Wartungsendpunkt
| POST /wartung/schedule mit Token. Kurze, wiederanlauffähige Läufe statt
| eines dauerhaften Prozesses, weil IONOS Webhosting keinen Daemon erlaubt.
|
*/

// Lebenszeichen für flow:check-config: nur mehr als 15 Minuten ohne Lauf
// gelten dort als Fehler, siehe CheckConfigCommand.
Schedule::call(function (): void {
    Cache::put('scheduler.last_run', now(), now()->addDay());
})->everyMinute()->name('scheduler.heartbeat')->onOneServer();

Schedule::command('queue:work database --stop-when-empty --max-time=45 --tries=3 --backoff=30')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground(false);

Schedule::command('flow:check-config --quiet')
    ->dailyAt('06:00');
