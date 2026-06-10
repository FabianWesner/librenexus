<?php

use Symfony\Component\HttpFoundation\Cookie;

test('security headers are set on every response', function () {
    $response = $this->get('/');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('X-Frame-Options', 'DENY');

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("form-action 'self'");
});

test('security headers are set on error responses too', function () {
    $response = $this->get('/definitely-not-a-real-page');

    $response->assertNotFound()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('the session cookie is http-only and same-site lax', function () {
    config()->set('session.driver', 'file');

    $response = $this->get('/');

    $sessionCookie = collect($response->baseResponse->headers->getCookies())
        ->first(fn (Cookie $cookie): bool => $cookie->getName() === config('session.cookie'));

    expect($sessionCookie)->not->toBeNull()
        ->and($sessionCookie->isHttpOnly())->toBeTrue()
        ->and($sessionCookie->getSameSite())->toBe('lax');
});

test('the session cookie is marked secure when configured for production', function () {
    config()->set('session.driver', 'file');
    config()->set('session.secure', true);

    $response = $this->get('/');

    $sessionCookie = collect($response->baseResponse->headers->getCookies())
        ->first(fn (Cookie $cookie): bool => $cookie->getName() === config('session.cookie'));

    expect($sessionCookie)->not->toBeNull()
        ->and($sessionCookie->isSecure())->toBeTrue();
});
