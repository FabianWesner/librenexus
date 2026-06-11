<?php

use App\Enums\TeamRole;
use App\Http\Middleware\ResolvePublicTenant;
use App\Models\Team;
use App\Models\User;

/**
 * ARCH-ROUTING-3/4/5: the tenant-slug catch-all is registered last, so
 * static pages, system endpoints, and authenticated tenant routes always
 * take precedence, and reserved slugs can never shadow them.
 */
covers(ResolvePublicTenant::class);

test('static pages keep precedence over the tenant catch-all', function () {
    Team::factory()->create();

    $this->get('/pricing')->assertOk()->assertSee('Pricing');
    $this->get('/login')->assertOk();
    $this->get('/health')->assertOk();
});

test('an authenticated tenant dashboard keeps precedence over the catch-all', function () {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

    $this->actingAs($owner)
        ->get(route('dashboard', ['current_team' => $team->slug]))
        ->assertOk();
});

test('a known tenant slug resolves the public booking page', function () {
    $team = Team::factory()->create(['name' => 'Routing Clinic']);

    $this->get('/'.$team->slug)
        ->assertOk()
        ->assertSee('Routing Clinic');
});

test('an unknown tenant slug 404s', function () {
    $this->get('/slug-that-does-not-exist')->assertNotFound();
});

test('a reserved name never becomes a shadowing tenant slug', function () {
    $team = Team::factory()->create(['name' => 'Pricing']);

    expect($team->slug)->not->toBe('pricing');

    // The static page still wins, the tenant is reachable at its real slug.
    $this->get('/pricing')->assertOk()->assertSee('One plan. Everything included.');
    $this->get('/'.$team->slug)->assertOk()->assertSee('Pricing');
});
