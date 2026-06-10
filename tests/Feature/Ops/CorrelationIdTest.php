<?php

use App\Http\Middleware\AddCorrelationId;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

test('every response carries a generated correlation id header', function () {
    $response = $this->get('/health');

    $correlationId = $response->headers->get(AddCorrelationId::HEADER);

    expect($correlationId)->not->toBeNull()
        ->and(Str::isUuid((string) $correlationId))->toBeTrue();
});

test('a safe inbound request id is reused as the correlation id', function () {
    $response = $this->withHeader(AddCorrelationId::HEADER, 'trace-abc-12345678')
        ->get('/health');

    expect($response->headers->get(AddCorrelationId::HEADER))->toBe('trace-abc-12345678');
});

test('an unsafe inbound request id is replaced by a generated uuid', function () {
    $response = $this->withHeader(AddCorrelationId::HEADER, "bad value\nwith newline")
        ->get('/health');

    $correlationId = $response->headers->get(AddCorrelationId::HEADER);

    expect(Str::isUuid((string) $correlationId))->toBeTrue();
});

test('the correlation id is shared with the log context during the request', function () {
    $this->withHeader(AddCorrelationId::HEADER, 'trace-context-check-1')->get('/health');

    expect(Log::sharedContext())->toHaveKey('correlation_id', 'trace-context-check-1');
});

test('structured log lines are json and include the correlation id', function () {
    $logFile = sys_get_temp_dir().'/librenexus-structured-'.uniqid().'.log';
    config()->set('logging.channels.structured.path', $logFile);

    Log::shareContext(['correlation_id' => 'trace-log-format-1']);
    Log::channel('structured')->info('structured logging smoke test');

    $line = trim((string) file_get_contents($logFile));
    @unlink($logFile);

    $decoded = json_decode($line, true);

    expect($decoded)->toBeArray()
        ->and($decoded['message'])->toBe('structured logging smoke test')
        ->and($decoded['context']['correlation_id'])->toBe('trace-log-format-1');
});
