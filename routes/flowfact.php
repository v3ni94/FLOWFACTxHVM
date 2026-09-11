<?php

/*
|------------------------------------------------------------------------------
| FLOWFACT-Verwaltung (Adminbereich)
|------------------------------------------------------------------------------
|
| Routen des FLOWFACT-Connectors: Token, Verbindungstest, Schemaauswahl,
| Feldzuordnung (docs/connector.md Abschnitt 7). Nur für Administratoren
| (Datenvertrag Abschnitt 6). Alle Änderungen laufen über POST/DELETE.
|
*/

use App\Http\Controllers\Admin\FlowfactMappingController;
use App\Http\Controllers\Admin\FlowfactSettingsController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureUserIsActive::class, 'role:admin'])
    ->prefix('admin/flowfact')
    ->name('admin.flowfact.')
    ->group(function (): void {
        Route::get('/', [FlowfactSettingsController::class, 'edit'])->name('edit');
        Route::post('/token', [FlowfactSettingsController::class, 'storeToken'])->name('token.store');
        Route::delete('/token', [FlowfactSettingsController::class, 'destroyToken'])->name('token.destroy');
        Route::post('/einstellungen', [FlowfactSettingsController::class, 'updateSettings'])->name('settings.update');
        Route::post('/verbindung-testen', [FlowfactSettingsController::class, 'testConnection'])->name('test');
        Route::post('/schemata-laden', [FlowfactSettingsController::class, 'loadSchemas'])->name('schemas.load');
        Route::post('/zuordnung', [FlowfactMappingController::class, 'update'])->name('mapping.update');
    });
