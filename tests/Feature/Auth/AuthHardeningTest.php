<?php

use App\Models\User;
use App\Notifications\Auth\QueuedVerifyEmail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

test('registration rejects a duplicate email', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $response = $this->post('/register', [
        'name' => 'Second User',
        'email' => 'taken@example.com',
        'password' => 'password-123',
        'password_confirmation' => 'password-123',
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('registration rejects a password shorter than the policy minimum', function () {
    $response = $this->post('/register', [
        'name' => 'Weak Password',
        'email' => 'weak@example.com',
        'password' => 'short',
        'password_confirmation' => 'short',
    ]);

    $response->assertSessionHasErrors('password');
    $this->assertGuest();
});

test('the strict password policy rejects a weak password and accepts a strong one', function () {
    // Force the production policy through config (ARCH-CONFIG-2): no
    // environment faking, the flag alone decides.
    config(['auth.password_policy.strict' => true]);

    // The strict policy includes an uncompromised() check against the
    // pwned-passwords range API; an empty range response means "not leaked".
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

    // Eight lowercase characters and a digit pass the relaxed policy but
    // must fail the strict one (too short, no mixed case, no symbols).
    $this->post(route('register.store'), [
        'name' => 'Strict Policy',
        'email' => 'strict@example.com',
        'password' => 'weakpass1',
        'password_confirmation' => 'weakpass1',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();

    $this->post(route('register.store'), [
        'name' => 'Strict Policy',
        'email' => 'strict@example.com',
        'password' => 'Str0ng!Passw0rd#42',
        'password_confirmation' => 'Str0ng!Passw0rd#42',
    ])->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('the session id is regenerated on login to prevent session fixation', function () {
    $user = User::factory()->create();

    $this->get(route('login'));

    $guestSessionId = session()->getId();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);

    expect(session()->getId())->not->toBe($guestSessionId)
        ->and($guestSessionId)->not->toBe('');
});

test('a user can stay logged in with remember me', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => 'on',
    ]);

    $this->assertAuthenticatedAs($user);

    $rememberCookie = collect($response->baseResponse->headers->getCookies())
        ->first(fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_web_'));

    expect($rememberCookie)->not->toBeNull()
        ->and($user->fresh()->remember_token)->not->toBeNull();
});

test('login is throttled after repeated failures', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);
    }

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertStatus(429);
    $this->assertGuest();
});

test('password reset link requests are throttled per ip', function () {
    foreach (range(1, 5) as $attempt) {
        $this->post('/forgot-password', ['email' => "person{$attempt}@example.com"]);
    }

    $this->post('/forgot-password', ['email' => 'person6@example.com'])
        ->assertStatus(429);
});

test('a password reset token is single-use', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertSessionHasNoErrors();

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'other-password-123',
        'password_confirmation' => 'other-password-123',
    ])->assertSessionHasErrors('email');
});

test('an expired password reset token is rejected', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->travel(config()->integer('auth.passwords.users.expire') + 5)->minutes();

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertSessionHasErrors('email');
});

test('an unverified user is redirected away from verified routes', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('appearance.edit'))
        ->assertRedirect(route('verification.notice'));
});

test('changing the email re-triggers verification', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'new-address@example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->email)->toBe('new-address@example.com')
        ->and($user->email_verified_at)->toBeNull();

    Livewire::test('pages::settings.profile')
        ->call('resendVerificationNotification');

    Notification::assertSentTo($user, QueuedVerifyEmail::class);
});

test('the appearance settings page renders for a verified user', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('appearance.edit'))
        ->assertOk()
        ->assertSee('Appearance');
});
