<?php

use App\Models\Team;
use Illuminate\Support\Facades\Route;

$publicRoutes = [
    'home' => '/',
    'pricing' => '/pricing',
    'docs' => '/docs',
    'open-source' => '/open-source',
    'privacy' => '/privacy',
    'imprint' => '/imprint',
];

test('public page renders successfully', function (string $routeName, string $path) {
    $response = $this->get($path);

    $response->assertOk();

    expect(route($routeName, absolute: false))->toBe($path === '/' ? '/' : $path);
})->with(array_map(null, array_keys($publicRoutes), array_values($publicRoutes)));

test('the global footer appears on every public page with resolving links', function (string $routeName) {
    $response = $this->get(route($routeName));

    $response->assertOk()
        ->assertSee('MIT licensed')
        ->assertSee(route('pricing'))
        ->assertSee(route('docs'))
        ->assertSee(route('open-source'))
        ->assertSee(route('privacy'))
        ->assertSee(route('imprint'))
        ->assertSee(config('app.repository_url'));
})->with(['home', 'pricing', 'docs', 'open-source', 'privacy', 'imprint']);

test('every internal link target on the public pages resolves', function () {
    // The homepage demo CTA points at the seeded demo tenant (Epic 09).
    Team::factory()->create(['name' => 'Demo Clinic', 'slug' => 'demo-clinic']);

    $pages = ['home', 'pricing', 'docs', 'open-source', 'privacy', 'imprint'];

    $internalPaths = collect($pages)
        ->flatMap(function (string $routeName): array {
            preg_match_all('/href="([^"]+)"/', $this->get(route($routeName))->getContent(), $matches);

            return $matches[1];
        })
        ->filter(fn (string $href): bool => str_starts_with($href, config()->string('app.url')) || str_starts_with($href, '/'))
        ->map(fn (string $href): string => parse_url($href, PHP_URL_PATH) ?: '/')
        ->reject(fn (string $path): bool => str_contains($path, '.'))
        ->unique()
        ->values();

    expect($internalPaths)->not->toBeEmpty();

    foreach ($internalPaths as $path) {
        $this->get($path)->assertSuccessful();
    }
});

test('the repository ships an MIT license linked from the open-source page', function () {
    $license = file_get_contents(base_path('LICENSE'));

    expect($license)->toContain('MIT License');

    $this->get(route('open-source'))
        ->assertOk()
        ->assertSee('LICENSE');
});

test('marketing copy never uses the em-dash', function () {
    foreach (['home', 'pricing', 'docs', 'open-source', 'privacy', 'imprint'] as $routeName) {
        expect($this->get(route($routeName))->getContent())->not->toContain('—');
    }
});

test('no auth-only routes leak into the public page set', function () {
    foreach (['pricing', 'docs', 'open-source', 'privacy', 'imprint'] as $routeName) {
        $route = Route::getRoutes()->getByName($routeName);

        expect($route?->gatherMiddleware())->not->toContain('auth');
    }
});
