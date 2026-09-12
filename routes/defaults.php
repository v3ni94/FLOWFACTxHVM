<?php

/*
|------------------------------------------------------------------------------
| Vorgaben (Adminbereich)
|------------------------------------------------------------------------------
|
| Standardwerte nach Masterprompt Abschnitt 18: Land, Währung (nur Anzeige),
| Standardansprechpartner, Portalvorauswahl je Vermarktungsart, Muster für die
| interne Bezeichnung und neutrale Textbausteine für "Beschreibung Sonstiges".
|
*/

use App\Http\Controllers\Admin\DefaultsController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureUserIsActive::class, 'role:admin'])
    ->prefix('admin/vorgaben')
    ->name('admin.defaults.')
    ->group(function (): void {
        Route::get('/', [DefaultsController::class, 'edit'])->name('edit');
        Route::post('/', [DefaultsController::class, 'update'])->name('update');
    });
