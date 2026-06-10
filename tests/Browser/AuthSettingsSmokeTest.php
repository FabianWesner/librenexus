<?php

use App\Models\User;
use Illuminate\Support\Facades\Password;
use PragmaRX\Google2FA\Google2FA;

test('public auth page is accessible and error free', function (string $path) {
    visit($path)
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
})->with([
    'login' => '/login',
    'register' => '/register',
    'forgot password' => '/forgot-password',
]);

test('the reset-password page is accessible and error free', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    visit("/reset-password/{$token}?email=".urlencode($user->email))
        ->assertSee('password')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the two-factor challenge page is accessible and error free', function () {
    $google2fa = new Google2FA;

    $user = User::factory()->create([
        'two_factor_secret' => encrypt($google2fa->generateSecretKey()),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one'])),
        'two_factor_confirmed_at' => now(),
    ]);

    visit('/login')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->click('Log in')
        ->assertPathIs('/two-factor-challenge')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the verify-email notice is accessible and error free', function () {
    $this->actingAs(User::factory()->unverified()->create());

    visit(route('verification.notice', absolute: false))
        ->assertSee('Verify')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the profile settings page is accessible and error free', function () {
    $this->actingAs(User::factory()->create());

    visit('/settings/profile')
        ->assertSee('Profile')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});

test('the appearance settings page is accessible and persists the choice', function () {
    $this->actingAs(User::factory()->create());

    $page = visit('/settings/appearance');

    $page->assertSee('Appearance')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();

    $page->click('Dark')
        ->navigate('/settings/appearance')
        ->assertScript('document.documentElement.classList.contains("dark")', true);
});

test('the security settings page is accessible after confirming the password', function () {
    $this->actingAs(User::factory()->create());

    visit('/settings/security')
        ->assertSee('Confirm password')
        ->assertNoAccessibilityIssues()
        ->fill('password', 'password')
        ->click('Confirm')
        ->assertSee('Two-factor')
        ->assertNoJavascriptErrors()
        ->assertNoAccessibilityIssues();
});
