<?php

/*
|------------------------------------------------------------------------------
| KI-Texte (ADR-009)
|------------------------------------------------------------------------------
|
| Der Provider "fake" verhindert jeden externen Aufruf und ist die Vorgabe für
| Tests und lokale Entwicklung ohne Schlüssel. Das Modell ist im Adminbereich
| änderbar (Datenvertrag Abschnitt 2.11, Schlüssel ki.provider, ki.modell).
|
*/

use App\Http\Controllers\Admin\KiSettingsController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureUserIsActive::class, 'role:admin'])
    ->prefix('admin/ki')
    ->name('admin.ki.')
    ->group(function (): void {
        Route::get('/', [KiSettingsController::class, 'edit'])->name('edit');
        Route::post('/schluessel', [KiSettingsController::class, 'storeApiKey'])->name('key.store');
        Route::delete('/schluessel', [KiSettingsController::class, 'destroyApiKey'])->name('key.destroy');
        Route::post('/einstellungen', [KiSettingsController::class, 'updateSettings'])->name('settings.update');
        Route::post('/verbindung-testen', [KiSettingsController::class, 'testConnection'])->name('test');
    });
