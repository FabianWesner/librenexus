<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureTeamMembership;
use App\Http\Middleware\ResolvePublicTenant;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;

Route::view('/', 'marketing.home')->name('home');
Route::view('pricing', 'marketing.pricing')->name('pricing');
Route::view('docs', 'marketing.docs')->name('docs');
Route::view('open-source', 'marketing.open-source')->name('open-source');
Route::view('privacy', 'marketing.privacy')->name('privacy');
Route::view('imprint', 'marketing.imprint')->name('imprint');

Route::get('health', HealthController::class)->name('health');

// Customer self-service entry point: the token is the credential, no auth
// and no tenant slug involved (SEC-TOKEN, pages.md §Customer self-service).
Route::livewire('manage/{token}', 'pages::booking.manage')->name('booking.manage');

// Re-register Fortify's reset-link endpoint with an IP throttle (SEC-RATE-1);
// Fortify only consults its limiter config for login and two-factor. This
// route replaces the package route because it shares the exact URI + method.
Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware(['guest:web', 'throttle:password-reset'])
    ->name('password.email');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::livewire('dashboard', 'pages::dashboard.index')->name('dashboard');
        Route::livewire('staff', 'pages::staff.index')->name('staff.index');
        Route::livewire('staff/{staff}/availability', 'pages::staff.availability')->name('staff.availability');
        Route::livewire('services', 'pages::services.index')->name('services.index');
        Route::livewire('appointments', 'pages::appointments.index')->name('appointments.index');
        Route::livewire('calendar', 'pages::appointments.calendar')->name('calendar.index');
    });

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';

// Public booking: the tenant-slug catch-all is registered LAST so every
// static and authenticated route above wins (ARCH-ROUTING-3); reserved
// slugs additionally guarantee a tenant can never shadow a static page
// (ARCH-ROUTING-4/5, App\Rules\TeamName).
Route::middleware(ResolvePublicTenant::class)->group(function () {
    Route::livewire('{tenant}', 'pages::booking.show')->name('booking.show');
    Route::livewire('{tenant}/book/confirmed/{token}', 'pages::booking.confirmed')->name('booking.confirmed');
});
