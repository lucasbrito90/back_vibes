<?php

use App\Telemetry\Console\ConsoleCommandTelemetry;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Logging\HttpErrorContextLogTap;
use App\Telemetry\Logging\QueueErrorContextLogTap;
use App\Telemetry\Logging\TraceCorrelationLogTap;
use App\Telemetry\Queue\QueueExecutionTelemetry;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Tests\Support\Telemetry\RecordingMeter;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Telemetry\TelemetryRecorder;

/**
 * Phase 7B.2, Part 9 — Structured Log Alignment (queue side). Mirrors the
 * construction pattern HttpErrorContextLogTapTest (Phase 7B.1) already
 * established for HttpErrorContextLogTap.
 */
function tappedQueueErrorLogger(): array
{
    $monolog = new Monolog('test');
    $handler = new TestHandler;
    $monolog->pushHandler($handler);

    $logger = new Logger($monolog, app('events'));
    (new QueueErrorContextLogTap)($logger);

    return [$logger, $handler];
}

function mockedQueueJobFor(string $class, string $queue = 'default'): QueueJob
{
    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('resolveQueuedJobClass')->andReturn($class);
    $job->shouldReceive('getQueue')->andReturn($queue);
    $job->shouldReceive('attempts')->andReturn(2);
    $job->shouldReceive('isReleased')->andReturn(false)->byDefault();
    $job->shouldReceive('payload')->andReturn([])->byDefault();

    return $job;
}

afterEach(function () {
    Mockery::close();
});

test('every configured log channel receives the queue error context tap', function () {
    foreach (array_keys(config('logging.channels')) as $channel) {
        expect(config("logging.channels.{$channel}.tap"))->toContain(QueueErrorContextLogTap::class);
    }
});

test('an exception seen by QueueExecutionTelemetry is enriched with queue, connection, job_name, and attempt only', function () {
    $recorder = new TelemetryRecorder;
    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->bind(Meter::class, fn () => new RecordingMeter($recorder));
    app()->forgetInstance(QueueExecutionTelemetry::class);

    $job = mockedQueueJobFor('App\\Jobs\\Example\\ChargeInvoice', 'billing');
    $exception = new RuntimeException('provider timeout');

    Event::dispatch(new JobProcessing('database', $job));
    Event::dispatch(new JobExceptionOccurred('database', $job, $exception));
    Event::dispatch(new JobFailed('database', $job, $exception));

    [$logger, $handler] = tappedQueueErrorLogger();

    $logger->error('Job failed.', ['exception' => $exception]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->toBe([
        'queue' => 'billing',
        'connection' => 'database',
        'job_name' => 'ChargeInvoice',
        'attempt' => 2,
    ]);
});

test('an exception never seen by QueueExecutionTelemetry is left untouched', function () {
    [$logger, $handler] = tappedQueueErrorLogger();

    $logger->error('Unrelated exception.', ['exception' => new RuntimeException('never processed as a job')]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->toBe([]);
});

test('records without an exception in context are left untouched', function () {
    [$logger, $handler] = tappedQueueErrorLogger();

    $logger->info('Routine informational message.', ['queue' => 'billing']);

    $record = $handler->getRecords()[0];

    expect($record->context)->toBe(['queue' => 'billing'])
        ->and($record->extra)->toBe([]);
});

// Part 9 — "the HTTP log tap must not add HTTP fields to queue logs", and
// the queue tap must never add HTTP fields either.
test('the queue error context never contains HTTP-shaped fields, and the HTTP tap never adds fields to a queue-context log', function () {
    app()->instance('request', Request::create('/', 'GET'));

    $recorder = new TelemetryRecorder;
    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->bind(Meter::class, fn () => new RecordingMeter($recorder));
    app()->forgetInstance(QueueExecutionTelemetry::class);

    $job = mockedQueueJobFor('App\\Jobs\\Example\\SendDigest', 'default');
    $exception = new RuntimeException('digest failed');

    Event::dispatch(new JobProcessing('database', $job));
    Event::dispatch(new JobExceptionOccurred('database', $job, $exception));
    Event::dispatch(new JobFailed('database', $job, $exception));

    $monolog = new Monolog('test');
    $handler = new TestHandler;
    $monolog->pushHandler($handler);
    $logger = new Logger($monolog, app('events'));
    (new TraceCorrelationLogTap)($logger);
    (new HttpErrorContextLogTap)($logger);
    (new QueueErrorContextLogTap)($logger);

    $logger->error('Job failed.', ['exception' => $exception]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->not->toHaveKeys(['http_method', 'http_route', 'http_status_code'])
        ->and($record->extra)->toHaveKeys(['queue', 'connection', 'job_name', 'attempt']);
});

test('a console command in context does not leak console fields into a queue job failure log', function () {
    app()->forgetInstance(ConsoleCommandTelemetry::class);

    $recorder = new TelemetryRecorder;
    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->bind(Meter::class, fn () => new RecordingMeter($recorder));
    app()->forgetInstance(QueueExecutionTelemetry::class);

    $job = mockedQueueJobFor('App\\Jobs\\Example\\SendDigest');
    $exception = new RuntimeException('digest failed');

    Event::dispatch(new JobProcessing('database', $job));
    Event::dispatch(new JobExceptionOccurred('database', $job, $exception));
    Event::dispatch(new JobFailed('database', $job, $exception));

    [$logger, $handler] = tappedQueueErrorLogger();
    $logger->error('Job failed.', ['exception' => $exception]);

    $record = $handler->getRecords()[0];

    expect($record->extra)->not->toHaveKeys(['command', 'exit_code']);
});
