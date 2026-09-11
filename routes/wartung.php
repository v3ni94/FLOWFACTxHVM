<?php

use App\Http\Controllers\Wartung\InstallController;
use App\Http\Controllers\Wartung\ScheduleController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Wartungsendpunkte
|------------------------------------------------------------------------------
|
| Für Hosting ohne Shellzugang (ADR-001, Architektur Abschnitt 6). Nur POST,
| eigener Token je Endpunkt, geprüft in den Controllern mit hash_equals. Ein
| leerer Token schaltet den jeweiligen Endpunkt ab (404). GET liefert 405,
| weil die Route ausschließlich auf POST registriert ist.
|
*/

Route::post('/wartung/schedule', ScheduleController::class)
    ->middleware('throttle:10,1')
    ->name('wartung.schedule');

Route::post('/wartung/install', InstallController::class)
    ->middleware('throttle:10,1')
    ->name('wartung.install');
