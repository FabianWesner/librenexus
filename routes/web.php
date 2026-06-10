<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;

Route::view('/', 'marketing.home')->name('home');
Route::view('pricing', 'marketing.pricing')->name('pricing');
Route::view('docs', 'marketing.docs')->name('docs');
Route::view('open-source', 'marketing.open-source')->name('open-source');
Route::view('privacy', 'marketing.privacy')->name('privacy');
Route::view('imprint', 'marketing.imprint')->name('imprint');

Route::get('health', HealthController::class)->name('health');

// Re-register Fortify's reset-link endpoint with an IP throttle (SEC-RATE-1);
// Fortify only consults its limiter config for login and two-factor. This
// route replaces the package route because it shares the exact URI + method.
Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['guest:web', 'throttle:password-reset'])
    ->name('password.email');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
        Route::livewire('staff', 'pages::staff.index')->name('staff.index');
        Route::livewire('services', 'pages::services.index')->name('services.index');
    });

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
