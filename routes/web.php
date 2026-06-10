<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'marketing.home')->name('home');
Route::view('pricing', 'marketing.pricing')->name('pricing');
Route::view('docs', 'marketing.docs')->name('docs');
Route::view('open-source', 'marketing.open-source')->name('open-source');
Route::view('privacy', 'marketing.privacy')->name('privacy');
Route::view('imprint', 'marketing.imprint')->name('imprint');

Route::get('health', HealthController::class)->name('health');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::view('dashboard', 'dashboard')->name('dashboard');
    });

Route::middleware(['auth'])->group(function () {
    Route::livewire('invitations/{invitation}/accept', 'pages::teams.accept-invitation')->name('invitations.accept');
});

require __DIR__.'/settings.php';
