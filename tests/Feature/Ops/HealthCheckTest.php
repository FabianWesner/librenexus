<?php

use Illuminate\Support\Facades\Route;

test('health check reports ok when the database is reachable', function () {
    $response = $this->getJson('/health');

    $response->assertOk()
        ->assertJson([
            'status' => 'ok',
            'database' => 'ok',
        ])
        ->assertJsonStructure(['status', 'database', 'time']);
});

test('health check reports 503 when the database is unreachable', function () {
    $workingConnection = config()->string('database.default');

    config()->set('database.connections.broken', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'unreachable',
        'username' => 'nobody',
        'password' => 'nothing',
    ]);
    config()->set('database.default', 'broken');

    $response = $this->getJson('/health');

    config()->set('database.default', $workingConnection);

    $response->assertServiceUnavailable()
        ->assertJson([
            'status' => 'degraded',
            'database' => 'unreachable',
        ]);
});

test('health check requires no authentication and no rate limiting', function () {
    $route = Route::getRoutes()->getByName('health');

    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();

    expect($middleware)->not->toContain('auth')
        ->and(collect($middleware)->filter(
            fn (mixed $name): bool => is_string($name) && str_starts_with($name, 'throttle')
        ))->toBeEmpty();
});
