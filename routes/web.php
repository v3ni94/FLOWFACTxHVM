<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Account\TwoFactorController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\ListingController;
use App\Http\Controllers\App\ListingMediaController;
use App\Http\Controllers\App\ListingTextController;
use App\Http\Controllers\App\MediaStreamController;
use App\Http\Controllers\App\ReviewController;
use App\Http\Controllers\App\Steps\StepDispatcher;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
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

    Route::get('/passwort-vergessen', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/passwort-vergessen', [ForgotPasswordController::class, 'store'])->name('password.email');
    Route::get('/passwort-zuruecksetzen/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/passwort-zuruecksetzen', [ResetPasswordController::class, 'store'])->name('password.update');

    Route::get('/einladung/{token}', [InvitationController::class, 'show'])
        ->middleware('signed')
        ->name('invitation.show');
    Route::post('/einladung/{token}', [InvitationController::class, 'store'])->name('invitation.accept');
});

Route::middleware(['auth', EnsureUserIsActive::class])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/app/dashboard', [DashboardController::class, 'index'])->name('app.dashboard');

    // Objekte (Masterprompt 7, 24)
    Route::get('/app/objekte', [ListingController::class, 'index'])->name('app.listings.index');
    Route::post('/app/objekte', [ListingController::class, 'store'])->name('app.listings.create');
    Route::get('/app/objekte/{listing}', [ListingController::class, 'show'])->name('app.listings.show');
    Route::get('/app/objekte/{listing}/historie', [ListingController::class, 'history'])->name('app.listings.history');
    Route::post('/app/objekte/{listing}/duplizieren', [ListingController::class, 'duplicate'])->name('app.listings.duplicate');
    Route::post('/app/objekte/{listing}/archivieren', [ListingController::class, 'archive'])->name('app.listings.archive');

    // Acht Schritte über den StepDispatcher (docs/masterprompt-abgleich.md B.1)
    Route::get('/app/objekte/{listing}/schritt/{schritt}', [StepDispatcher::class, 'show'])
        ->whereNumber('schritt')
        ->name('app.listings.step');
    Route::post('/app/objekte/{listing}/schritt/{schritt}', [StepDispatcher::class, 'store'])
        ->whereNumber('schritt')
        ->name('app.listings.step.store');
    Route::patch('/app/objekte/{listing}/schritt/{schritt}', [StepDispatcher::class, 'autosave'])
        ->whereNumber('schritt')
        ->name('app.listings.step.autosave');

    // Prüfen und veröffentlichen (Masterprompt 17 bis 21, 24)
    Route::get('/app/objekte/{listing}/pruefen', [ReviewController::class, 'review'])->name('app.listings.review');
    Route::post('/app/objekte/{listing}/status/bereit', [ReviewController::class, 'markiereBereit'])->name('app.listings.status.bereit');
    Route::post('/app/objekte/{listing}/uebertragen', [ReviewController::class, 'transfer'])->name('app.listings.transfer');
    Route::post('/app/objekte/{listing}/veroeffentlichen', [ReviewController::class, 'publish'])->name('app.listings.publish');
    Route::post('/app/objekte/{listing}/zurueckziehen', [ReviewController::class, 'withdraw'])->name('app.listings.withdraw');

    // Medien (Schritt 6)
    Route::post('/app/objekte/{listing}/medien', [ListingMediaController::class, 'store'])->name('app.listings.media.store');
    Route::delete('/app/objekte/{listing}/medien/{media}', [ListingMediaController::class, 'destroy'])->name('app.listings.media.destroy');
    Route::post('/app/objekte/{listing}/medien/sortierung', [ListingMediaController::class, 'sort'])->name('app.listings.media.sort');
    Route::post('/app/objekte/{listing}/medien/{media}', [ListingMediaController::class, 'update'])->name('app.listings.media.update');
    Route::post('/app/objekte/{listing}/medien/{media}/drehen', [ListingMediaController::class, 'rotate'])->name('app.listings.media.rotate');

    // Texte (Schritte 7 und 8)
    Route::post('/app/objekte/{listing}/texte/vorschlag', [ListingTextController::class, 'generate'])->name('app.listings.texts.generate');
    Route::post('/app/objekte/{listing}/texte/{text}/uebernehmen', [ListingTextController::class, 'accept'])->name('app.listings.texts.accept');
    Route::post('/app/objekte/{listing}/texte/ueberarbeiten', [ListingTextController::class, 'revise'])->name('app.listings.texts.revise');

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
        Route::get('/users/invite', [UserController::class, 'invite'])->name('users.invite');
        Route::post('/users/invite', [UserController::class, 'storeInvitation'])->name('users.invite.store');
        Route::post('/users/invitations/{invitation}/resend', [UserController::class, 'resendInvitation'])->name('users.invitations.resend');
        Route::post('/users/invitations/{invitation}/revoke', [UserController::class, 'revokeInvitation'])->name('users.invitations.revoke');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('/users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
        Route::post('/users/{user}/reset-two-factor', [UserController::class, 'resetTwoFactor'])->name('users.reset-two-factor');
    });
});
