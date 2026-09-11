<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Account\TwoFactorController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\ListingController;
use App\Http\Controllers\App\ListingMediaController;
use App\Http\Controllers\App\ListingTextController;
use App\Http\Controllers\App\ListingWizardController;
use App\Http\Controllers\App\MediaStreamController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('app.dashboard')
        : redirect()->route('login');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');

    Route::get('/two-factor/challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    Route::post('/two-factor/challenge', [TwoFactorChallengeController::class, 'store'])->name('two-factor.challenge.store');
});

Route::middleware(['auth', EnsureUserIsActive::class])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/app/dashboard', [DashboardController::class, 'index'])->name('app.dashboard');

    Route::get('/app/objekte', [ListingController::class, 'index'])->name('app.listings.index');
    Route::post('/app/objekte', [ListingController::class, 'store'])->name('app.listings.create');
    Route::get('/app/objekte/{listing}', [ListingController::class, 'show'])->name('app.listings.show');
    Route::post('/app/objekte/{listing}/archivieren', [ListingController::class, 'archive'])->name('app.listings.archive');
    Route::post('/app/objekte/{listing}/status/bereit', [ListingController::class, 'markiereBereit'])->name('app.listings.status.bereit');
    Route::post('/app/objekte/{listing}/uebertragen', [ListingController::class, 'transfer'])->name('app.listings.transfer');
    Route::post('/app/objekte/{listing}/veroeffentlichen', [ListingController::class, 'publish'])->name('app.listings.publish');
    Route::post('/app/objekte/{listing}/zurueckziehen', [ListingController::class, 'withdraw'])->name('app.listings.withdraw');

    Route::get('/app/objekte/{listing}/schritt/{schritt}', [ListingWizardController::class, 'step'])
        ->whereNumber('schritt')
        ->name('app.listings.step');
    Route::post('/app/objekte/{listing}/schritt/{schritt}', [ListingWizardController::class, 'stepStore'])
        ->whereNumber('schritt')
        ->name('app.listings.step.store');

    Route::post('/app/objekte/{listing}/medien', [ListingMediaController::class, 'store'])->name('app.listings.media.store');
    Route::delete('/app/objekte/{listing}/medien/{media}', [ListingMediaController::class, 'destroy'])->name('app.listings.media.destroy');
    Route::post('/app/objekte/{listing}/medien/sortierung', [ListingMediaController::class, 'sort'])->name('app.listings.media.sort');
    Route::post('/app/objekte/{listing}/medien/{media}', [ListingMediaController::class, 'update'])->name('app.listings.media.update');

    Route::post('/app/objekte/{listing}/texte/vorschlag', [ListingTextController::class, 'generate'])->name('app.listings.texts.generate');
    Route::post('/app/objekte/{listing}/texte/{text}/uebernehmen', [ListingTextController::class, 'accept'])->name('app.listings.texts.accept');

    Route::get('/medien/{media}/{variante}', [MediaStreamController::class, 'show'])
        ->where('variante', 'original|vorschau')
        ->middleware('signed')
        ->name('app.media.show');

    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('/account/password', [PasswordController::class, 'update'])->name('account.password.update');
    Route::post('/account/two-factor/setup', [TwoFactorController::class, 'setup'])->name('account.two-factor.setup');
    Route::post('/account/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('account.two-factor.confirm');
    Route::delete('/account/two-factor', [TwoFactorController::class, 'disable'])->name('account.two-factor.disable');
    Route::post('/account/two-factor/recovery', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('account.two-factor.recovery');

    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('/users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
        Route::post('/users/{user}/reset-two-factor', [UserController::class, 'resetTwoFactor'])->name('users.reset-two-factor');
    });
});
