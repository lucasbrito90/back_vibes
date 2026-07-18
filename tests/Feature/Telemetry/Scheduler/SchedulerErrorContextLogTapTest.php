<?php

use App\Telemetry\Console\ConsoleCommandTelemetry;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Logging\ConsoleErrorContextLogTap;
use App\Telemetry\Logging\HttpErrorContextLogTap;
use App\Telemetry\Logging\QueueErrorContextLogTap;
use App\Telemetry\Logging\SchedulerErrorContextLogTap;
use App\Telemetry\Logging\TraceCorrelationLogTap;
use App\Telemetry\Queue\QueueExecutionTelemetry;
use App\Telemetry\Scheduler\SchedulerExecutionTelemetry;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
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
 * Phase 7B.3, Part 10 — Structured Log Alignment (Scheduler side). Mirrors
 * the construction pattern ConsoleErrorContextLogTapTest/
 * QueueErrorContextLogTapTest already established.
 */
function tappedSchedulerErrorLogger(): array
{
    $monolog = new Monolog('test');
    $handler = new TestHandler;
    $monolog->pushHandler($handler);

    $logger = new Logger($monolog, app('events'));
    (new SchedulerErrorContextLogTap)($logger);

    return [$logger, $handler];
}

/**
 * Fails the scheduled event exactly the way a real before()-callback
 * exception or a throwing scheduled closure would — with no matching
 * ScheduledTaskFinished ever dispatched — so scheduledTaskFailed() records
 * both the metric and the exception correlation from a single stack
 * frame, exactly as it would in production.
 */
function failScheduledEventForLogTap(RuntimeException $exception): void
{
    app()->forgetInstance(SchedulerExecutionTelemetry::class);

    $task = (new Schedule)->command('reports:send-weekly');

    Event::dispatch(new ScheduledTaskStarting($task));
    $task->exitCode = 1;
    Event::dispatch(new ScheduledTaskFailed($task, $exception));
}

test('every configured log channel receives the scheduler error context tap', function () {
    foreach (array_keys(config('logging.channels')) as $channel) {
        expect(config("logging.channels.{$channel}.tap"))->toContain(SchedulerErrorContextLogTap::class);
    }
});

test('an exception logged for a failed scheduled event is enriched with only safe, bounded scheduler fields', function () {
    $exception = new RuntimeException('scheduled event blew up');
    failScheduledEventForLogTap($exception);

    [$logger, $handler] = tappedSchedulerErrorLogger();
    $logger->error('Scheduled command failed.', ['exception' => $exception]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->toBe([
        'scheduler_event' => 'command:reports:send-weekly',
        'scheduler_event_type' => 'command',
        'scheduler_execution_mode' => 'foreground',
        'scheduler_outcome' => 'failed',
        'scheduler_exit_code' => 1,
    ]);
});

test('an unrelated exception never seen by SchedulerExecutionTelemetry is left untouched', function () {
    app()->forgetInstance(SchedulerExecutionTelemetry::class);

    [$logger, $handler] = tappedSchedulerErrorLogger();
    $logger->error('Unrelated exception.', ['exception' => new RuntimeException('never seen by the scheduler')]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->toBe([]);
});

test('a stale exception from a previous, already-finished scheduled event does not leak onto a later unrelated log', function () {
    // A *successful* event never populates $exceptionContexts at all — the
    // WeakMap is only ever written from scheduledTaskFailed(), so a
    // stale/successful event can never bleed into an unrelated exception's
    // log context (Part 10: "Do not attach stale ambient Scheduler context
    // to unrelated later exceptions").
    app()->forgetInstance(SchedulerExecutionTelemetry::class);

    $task = (new Schedule)->command('cache:clear');
    Event::dispatch(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    Event::dispatch(new ScheduledTaskFinished($task, 0.01));

    [$logger, $handler] = tappedSchedulerErrorLogger();
    $logger->error('Unrelated exception.', ['exception' => new RuntimeException('never correlated')]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->toBe([]);
});

test('records without an exception in context are left untouched', function () {
    failScheduledEventForLogTap(new RuntimeException('ignored'));

    [$logger, $handler] = tappedSchedulerErrorLogger();
    $logger->info('Routine informational message.', ['scheduler_event' => 'command:reports:send-weekly']);

    $record = $handler->getRecords()[0];

    expect($record->context)->toBe(['scheduler_event' => 'command:reports:send-weekly'])
        ->and($record->extra)->toBe([]);
});

// Part 10 — log separation: HTTP/Queue/Console fields must never appear on
// a Scheduler-only failure log, and vice versa.
test('the scheduler error context never contains HTTP-shaped, queue-shaped, or console-shaped fields', function () {
    app()->instance('request', Request::create('/', 'GET'));

    $exception = new RuntimeException('scheduled event blew up');
    failScheduledEventForLogTap($exception);

    $monolog = new Monolog('test');
    $handler = new TestHandler;
    $monolog->pushHandler($handler);
    $logger = new Logger($monolog, app('events'));
    (new TraceCorrelationLogTap)($logger);
    (new HttpErrorContextLogTap)($logger);
    (new QueueErrorContextLogTap)($logger);
    (new ConsoleErrorContextLogTap)($logger);
    (new SchedulerErrorContextLogTap)($logger);

    $logger->error('Scheduled command failed.', ['exception' => $exception]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->not->toHaveKeys(['http_method', 'http_route', 'http_status_code', 'queue', 'connection', 'job_name', 'attempt', 'command'])
        ->and($record->extra)->toHaveKeys(['scheduler_event', 'scheduler_event_type', 'scheduler_execution_mode', 'scheduler_outcome', 'scheduler_exit_code']);
});

test('a queue job in context does not leak queue fields into a scheduler failure log, and vice versa', function () {
    $recorder = new TelemetryRecorder;
    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->bind(Meter::class, fn () => new RecordingMeter($recorder));
    app()->forgetInstance(QueueExecutionTelemetry::class);

    $exception = new RuntimeException('scheduled event blew up');
    failScheduledEventForLogTap($exception);

    [$logger, $handler] = tappedSchedulerErrorLogger();
    $logger->error('Scheduled command failed.', ['exception' => $exception]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->not->toHaveKeys(['queue', 'connection', 'job_name', 'attempt']);
});

test('a scheduler exception in context does not leak scheduler fields into an unrelated console command failure log', function () {
    $exception = new RuntimeException('scheduled event blew up');
    failScheduledEventForLogTap($exception);

    app()->forgetInstance(ConsoleCommandTelemetry::class);
    $input = new ArrayInput([]);
    $output = new NullOutput;
    Event::dispatch(new CommandStarting('migrate', $input, $output));
    Event::dispatch(new CommandFinished('migrate', $input, $output, 1));

    $monolog = new Monolog('test');
    $handler = new TestHandler;
    $monolog->pushHandler($handler);
    $logger = new Logger($monolog, app('events'));
    (new ConsoleErrorContextLogTap)($logger);
    (new SchedulerErrorContextLogTap)($logger);

    // A *different*, unrelated exception — never seen by
    // SchedulerExecutionTelemetry at all.
    $logger->error('Command failed.', ['exception' => new RuntimeException('unrelated console failure')]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->not->toHaveKeys(['scheduler_event', 'scheduler_event_type', 'scheduler_execution_mode', 'scheduler_outcome', 'scheduler_exit_code'])
        ->and($record->extra)->toHaveKeys(['command', 'exit_code', 'outcome']);
});
