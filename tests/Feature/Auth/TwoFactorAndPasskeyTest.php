<?php

use App\Models\User;
use App\Notifications\Auth\QueuedResetPassword;
use App\Notifications\Auth\QueuedVerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

function passwordConfirmed(): array
{
    return ['auth.password_confirmed_at' => time()];
}

/**
 * @return array{user: User, secret: string, recoveryCode: string}
 */
function userWithConfirmedTwoFactor(): array
{
    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();
    $recoveryCode = 'recovery-code-'.fake()->uuid();

    $user = User::factory()->create([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode([$recoveryCode])),
        'two_factor_confirmed_at' => now(),
    ]);

    return ['user' => $user, 'secret' => $secret, 'recoveryCode' => $recoveryCode];
}

test('a user can enable confirm and disable two factor authentication', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(passwordConfirmed());

    $this->post('/user/two-factor-authentication')->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();

    $otp = (new Google2FA)->getCurrentOtp(decrypt($user->two_factor_secret));

    $this->post('/user/confirmed-two-factor-authentication', ['code' => $otp])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->delete('/user/two-factor-authentication')->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

test('confirming two factor with a wrong code is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(passwordConfirmed());

    $this->post('/user/two-factor-authentication');

    $this->post('/user/confirmed-two-factor-authentication', ['code' => '000000'])
        ->assertSessionHasErrors();

    expect($user->refresh()->two_factor_confirmed_at)->toBeNull();
});

test('login with confirmed two factor completes via a valid totp code', function () {
    ['user' => $user, 'secret' => $secret] = userWithConfirmedTwoFactor();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect('/two-factor-challenge');

    $this->assertGuest();

    $this->post('/two-factor-challenge', [
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
});

test('login with two factor completes via a recovery code which is then consumed', function () {
    ['user' => $user, 'recoveryCode' => $recoveryCode] = userWithConfirmedTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->post('/two-factor-challenge', ['recovery_code' => $recoveryCode])
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);

    $remainingCodes = json_decode(decrypt($user->refresh()->two_factor_recovery_codes), true);

    expect($remainingCodes)->not->toContain($recoveryCode);
});

test('an invalid two factor code is rejected', function () {
    ['user' => $user] = userWithConfirmedTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    $this->post('/two-factor-challenge', ['code' => '000000'])
        ->assertSessionHasErrors();

    $this->assertGuest();
});

test('the two factor challenge is throttled after repeated failures', function () {
    ['user' => $user] = userWithConfirmedTwoFactor();

    $this->post('/login', ['email' => $user->email, 'password' => 'password']);

    foreach (range(1, 5) as $attempt) {
        $this->post('/two-factor-challenge', ['code' => '000000']);
    }

    $this->post('/two-factor-challenge', ['code' => '000000'])->assertStatus(429);
});

test('a user can list and delete their passkeys', function () {
    $user = User::factory()->create();

    $passkey = $user->passkeys()->create([
        'name' => 'MacBook Touch ID',
        'credential_id' => 'test-credential-id',
        'credential' => json_encode(['id' => 'test-credential-id', 'transports' => ['internal']]),
    ]);

    $this->actingAs($user)->withSession(passwordConfirmed());

    $component = Livewire::test('pages::settings.security');
    $component->assertSee('MacBook Touch ID');

    $component->call('confirmDelete', $passkey->id)
        ->call('deletePasskey');

    expect($user->passkeys()->count())->toBe(0);
});

test('a user cannot delete another users passkey', function () {
    $owner = User::factory()->create();
    $passkey = $owner->passkeys()->create([
        'name' => 'Owner Key',
        'credential_id' => 'owner-credential-id',
        'credential' => json_encode(['id' => 'owner-credential-id']),
    ]);

    $intruder = User::factory()->create();

    $this->actingAs($intruder)->withSession(passwordConfirmed());

    expect(fn () => Livewire::test('pages::settings.security')->call('confirmDelete', $passkey->id))
        ->toThrow(ModelNotFoundException::class);

    expect($owner->passkeys()->count())->toBe(1);
});

test('a passkey login with an invalid assertion fails without a server error', function () {
    $response = $this->postJson('/passkeys/login', [
        'answer' => ['garbage' => true],
    ]);

    expect($response->status())->toBeLessThan(500);
    $this->assertGuest();
});

test('the verification notification is queued', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->post('/email/verification-notification');

    Notification::assertSentTo($user, QueuedVerifyEmail::class, function ($notification) {
        return $notification instanceof ShouldQueue;
    });
});

test('the password reset notification is queued', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, QueuedResetPassword::class, function ($notification) {
        return $notification instanceof ShouldQueue;
    });
});
