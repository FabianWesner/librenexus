<?php

use Illuminate\Support\Facades\Schema;

test('the error-tracking integration point exists and is inert by default', function () {
    expect(config()->array('services.error_tracking'))->toHaveKey('dsn')
        ->and(config('services.error_tracking.dsn'))->toBeNull();
});

test('failed queue jobs are recorded in an inspectable store', function () {
    expect(config('queue.failed.driver'))->toBe('database-uuids')
        ->and(Schema::hasTable(config()->string('queue.failed.table', 'failed_jobs')))->toBeTrue();
});
