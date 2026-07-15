<?php

use App\Telemetry\Logging\HttpErrorContextLogTap;
use App\Telemetry\Logging\TraceCorrelationLogTap;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Illuminate\Routing\Route;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;

/**
 * Phase 7B.1, Part 6 — Structured Log Alignment. Mirrors the construction
 * pattern already established by TelemetryLoggingCorrelationTest (Phase 7A)
 * for TraceCorrelationLogTap.
 */
function tappedHttpErrorLogger(): array
{
    $monolog = new Monolog('test');
    $handler = new TestHandler;
    $monolog->pushHandler($handler);

    $logger = new Logger($monolog, app('events'));
    (new HttpErrorContextLogTap)($logger);

    return [$logger, $handler];
}

test('every configured log channel receives both the trace correlation and HTTP error context taps', function () {
    foreach (array_keys(config('logging.channels')) as $channel) {
        expect(config("logging.channels.{$channel}.tap"))
            ->toContain(TraceCorrelationLogTap::class)
            ->toContain(HttpErrorContextLogTap::class);
    }
});

test('an exception log record is enriched with method, route template, and estimated status when a route has resolved', function () {
    $request = Request::create('/api/vibes/42', 'GET');
    $route = new Route('GET', 'api/vibes/{vibe}', fn () => null);
    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);

    [$logger, $handler] = tappedHttpErrorLogger();

    $logger->error('Unhandled exception occurred.', ['exception' => new RuntimeException('boom')]);

    $records = $handler->getRecords();
    expect($records)->toHaveCount(1);

    $record = $records[0];

    expect($record->message)->toBe('Unhandled exception occurred.')
        ->and($record->context['exception'])->toBeInstanceOf(RuntimeException::class)
        ->and($record->extra)->toBe([
            'http_method' => 'GET',
            'http_route' => '/api/vibes/{vibe}',
            'http_status_code' => 500,
        ]);
});

test('the route template never contains the resolved path segment', function () {
    $request = Request::create('/api/vibes/999', 'GET');
    $route = new Route('GET', 'api/vibes/{vibe}', fn () => null);
    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);

    [$logger, $handler] = tappedHttpErrorLogger();

    $logger->error('Unhandled exception occurred.', ['exception' => new RuntimeException('boom')]);

    $record = $handler->getRecords()[0];

    expect($record->extra['http_route'])->not->toContain('999');
});

test('records without an exception in context are left untouched', function () {
    [$logger, $handler] = tappedHttpErrorLogger();

    $logger->info('Routine informational message.', ['schedule_id' => 42]);

    $record = $handler->getRecords()[0];

    expect($record->message)->toBe('Routine informational message.')
        ->and($record->context)->toBe(['schedule_id' => 42])
        ->and($record->extra)->toBe([]);
});

test('records are left untouched when no route has resolved on the current request (console/queue-shaped context)', function () {
    app()->instance('request', Request::create('/', 'GET'));

    [$logger, $handler] = tappedHttpErrorLogger();

    $logger->error('Unhandled exception occurred.', ['exception' => new RuntimeException('boom')]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->toBe([]);
});

test('request body, headers, and other request data are never exported', function () {
    $request = Request::create('/api/vibes/42', 'POST', ['email' => 'user@example.com', 'password' => 'secret']);
    $request->headers->set('Authorization', 'Bearer super-secret-token');
    $route = new Route('POST', 'api/vibes/{vibe}', fn () => null);
    $request->setRouteResolver(fn () => $route);
    app()->instance('request', $request);

    [$logger, $handler] = tappedHttpErrorLogger();

    $logger->error('Unhandled exception occurred.', ['exception' => new RuntimeException('boom')]);

    $serialized = json_encode($handler->getRecords()[0]->extra);

    expect($serialized)
        ->not->toContain('user@example.com')
        ->not->toContain('secret')
        ->not->toContain('super-secret-token');
});
