<?php

use App\Http\Controllers\Wartung\InstallController;
use App\Http\Controllers\Wartung\ScheduleController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Wartungsendpunkte
|------------------------------------------------------------------------------
|
| Für Hosting ohne Shellzugang (ADR-001, Architektur Abschnitt 6). GET oder
| POST, eigener Token je Endpunkt (Kopfzeile X-Cron-Token oder Parameter
| ?token=), geprüft in den Controllern mit hash_equals. Ein leerer Token
| schaltet den jeweiligen Endpunkt ab (404). Beide Aktionen sind idempotent,
| darum ist GET vertretbar; der Token gehört trotzdem nur in Cronjob und
| Passwortverwalter, nicht in Lesezeichen oder E-Mails.
|
*/

Route::match(['GET', 'POST'], '/wartung/schedule', ScheduleController::class)
    ->middleware('throttle:10,1')
    ->name('wartung.schedule');

Route::match(['GET', 'POST'], '/wartung/install', InstallController::class)
    ->middleware('throttle:10,1')
    ->name('wartung.install');
