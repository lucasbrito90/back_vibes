<?php

use App\Telemetry\Console\ConsoleCommandTelemetry;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Logging\ConsoleErrorContextLogTap;
use App\Telemetry\Logging\HttpErrorContextLogTap;
use App\Telemetry\Logging\QueueErrorContextLogTap;
use App\Telemetry\Logging\TraceCorrelationLogTap;
use App\Telemetry\Queue\QueueExecutionTelemetry;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Event;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\Telemetry\RecordingMeter;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Telemetry\TelemetryRecorder;

/**
 * Phase 7B.2, Part 9 — Structured Log Alignment (console side). Mirrors
 * the construction pattern HttpErrorContextLogTapTest (Phase 7B.1) already
 * established for HttpErrorContextLogTap.
 */
function tappedConsoleErrorLogger(): array
{
    $monolog = new Monolog('test');
    $handler = new TestHandler;
    $monolog->pushHandler($handler);

    $logger = new Logger($monolog, app('events'));
    (new ConsoleErrorContextLogTap)($logger);

    return [$logger, $handler];
}

function runConsoleLifecycleForLogTap(string $command, int $exitCode): void
{
    app()->forgetInstance(ConsoleCommandTelemetry::class);

    $input = new ArrayInput([]);
    $output = new NullOutput;

    Event::dispatch(new CommandStarting($command, $input, $output));
    Event::dispatch(new CommandFinished($command, $input, $output, $exitCode));
}

test('every configured log channel receives the console error context tap', function () {
    foreach (array_keys(config('logging.channels')) as $channel) {
        expect(config("logging.channels.{$channel}.tap"))->toContain(ConsoleErrorContextLogTap::class);
    }
});

test('an exception logged after a failed command is enriched with command, exit_code, and outcome only', function () {
    runConsoleLifecycleForLogTap('migrate', 1);

    [$logger, $handler] = tappedConsoleErrorLogger();

    $logger->error('Command failed.', ['exception' => new RuntimeException('migration blew up')]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->toBe([
        'command' => 'migrate',
        'exit_code' => 1,
        'outcome' => 'failed',
    ]);
});

test('an exception logged before any command has started in this process is left untouched', function () {
    app()->forgetInstance(ConsoleCommandTelemetry::class);

    [$logger, $handler] = tappedConsoleErrorLogger();

    $logger->error('Unrelated exception.', ['exception' => new RuntimeException('no command context yet')]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->toBe([]);
});

test('records without an exception in context are left untouched', function () {
    runConsoleLifecycleForLogTap('migrate', 0);

    [$logger, $handler] = tappedConsoleErrorLogger();

    $logger->info('Routine informational message.', ['command' => 'migrate']);

    $record = $handler->getRecords()[0];

    expect($record->context)->toBe(['command' => 'migrate'])
        ->and($record->extra)->toBe([]);
});

// Part 9 — log separation: the HTTP tap must not add HTTP fields to a
// console-context log, and the console tap must never add HTTP fields.
test('the console error context never contains HTTP-shaped or queue-shaped fields, and the HTTP tap adds nothing without a resolved route', function () {
    app()->instance('request', Request::create('/', 'GET'));

    runConsoleLifecycleForLogTap('schedule:run', 1);

    $monolog = new Monolog('test');
    $handler = new TestHandler;
    $monolog->pushHandler($handler);
    $logger = new Logger($monolog, app('events'));
    (new TraceCorrelationLogTap)($logger);
    (new HttpErrorContextLogTap)($logger);
    (new QueueErrorContextLogTap)($logger);
    (new ConsoleErrorContextLogTap)($logger);

    $logger->error('Command failed.', ['exception' => new RuntimeException('scheduler run failed')]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->not->toHaveKeys(['http_method', 'http_route', 'http_status_code', 'queue', 'connection', 'job_name', 'attempt'])
        ->and($record->extra)->toHaveKeys(['command', 'exit_code', 'outcome']);
});

test('a queue job in context does not leak queue fields into a console command failure log', function () {
    $meterRecorder = new TelemetryRecorder;
    app()->bind(Tracer::class, fn () => new RecordingTracer($meterRecorder));
    app()->bind(Meter::class, fn () => new RecordingMeter($meterRecorder));
    app()->forgetInstance(QueueExecutionTelemetry::class);

    runConsoleLifecycleForLogTap('queue:work', 0);

    [$logger, $handler] = tappedConsoleErrorLogger();
    $logger->error('Command failed.', ['exception' => new RuntimeException('unrelated')]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->not->toHaveKeys(['queue', 'connection', 'job_name', 'attempt']);
});
