<?php

test('public page loads without javascript or console errors', function (string $path, string $expectedText) {
    $page = visit($path);

    $page->assertSee($expectedText)
        ->assertNoJavascriptErrors()
        ->assertNoConsoleLogs();
})->with([
    'home' => ['/', 'Appointment scheduling your office actually controls'],
    'pricing' => ['/pricing', 'Frequently asked questions'],
    'docs' => ['/docs', 'User manual'],
    'open-source' => ['/open-source', 'Reproduce the benchmark'],
    'privacy' => ['/privacy', 'Privacy policy'],
    'imprint' => ['/imprint', 'Imprint'],
]);

test('the homepage is accessible', function () {
    visit('/')->assertNoAccessibilityIssues();
});
