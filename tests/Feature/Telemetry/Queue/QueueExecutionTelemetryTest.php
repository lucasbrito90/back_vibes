<?php

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Queue\QueueExecutionTelemetry;
use App\Telemetry\Queue\QueueJobNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Event;
use Tests\Support\Telemetry\RecordingMeter;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Telemetry\TelemetryRecorder;

/**
 * Phase 7B.2 — Queue telemetry, exercised through the real
 * App\Telemetry\Queue\QueueExecutionTelemetry listener exactly as it is
 * registered in TelemetryServiceProvider. Scenarios 1, 2, 4, 6, 7 dispatch
 * a real job through Laravel's `sync` queue connection (this test suite's
 * QUEUE_CONNECTION, phpunit.xml) so JobProcessing/JobProcessed/JobFailed
 * fire exactly as they would in production. `sync` has no retry/release
 * concept (Illuminate\Queue\SyncQueue::handleException() always calls
 * $job->fail() — never releases), so scenarios 3 and 5 dispatch the
 * Laravel events directly with a Mockery-mocked Job, the same "exercise
 * the real listener without the full infrastructure" pattern
 * HttpRequestTelemetryMiddlewareTest already uses for recordException().
 */
final class QueueTelemetrySuccessJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void {}
}

final class QueueTelemetryFailingJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        throw new RuntimeException('job exploded');
    }
}

final class QueueTelemetryOuterJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        QueueTelemetrySuccessJob::dispatch();
    }
}

function fakeQueueTelemetry(): TelemetryRecorder
{
    $recorder = new TelemetryRecorder;

    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->bind(Meter::class, fn () => new RecordingMeter($recorder));
    app()->forgetInstance(QueueExecutionTelemetry::class);

    return $recorder;
}

/**
 * @return list<array{name: string, amount: int|float, attributes: array<string, mixed>}>
 */
function queueJobTotalCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->counterCalls,
        fn (array $call) => $call['name'] === 'ixora.queue.job.total',
    ));
}

/**
 * @return list<array{name: string, value: int|float, attributes: array<string, mixed>}>
 */
function queueDurationCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->histogramCalls,
        fn (array $call) => $call['name'] === 'ixora.queue.job.duration',
    ));
}

function mockQueueJob(string $resolvedClass, ?string $queue = 'default', int $attempts = 1): QueueJob
{
    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('resolveQueuedJobClass')->andReturn($resolvedClass);
    $job->shouldReceive('getQueue')->andReturn($queue);
    $job->shouldReceive('attempts')->andReturn($attempts);
    $job->shouldReceive('isReleased')->andReturn(false)->byDefault();
    // Illuminate\Log\Context\ContextServiceProvider reads payload() off every
    // dispatched Queue\Events\* event's job to add ambient log context —
    // unrelated to this class's own telemetry, but every Job double needs it
    // stubbed to avoid an "unexpected method call" failure.
    $job->shouldReceive('payload')->andReturn([])->byDefault();

    return $job;
}

afterEach(function () {
    Mockery::close();
});

// 1. Successful job.
test('a successful job is counted once with a stable job name, correct queue/connection, and success outcome', function () {
    $recorder = fakeQueueTelemetry();

    QueueTelemetrySuccessJob::dispatch();

    $totals = queueJobTotalCalls($recorder);
    $durations = queueDurationCalls($recorder);

    expect($totals)->toHaveCount(1)
        ->and($durations)->toHaveCount(1);

    $attributes = $totals[0]['attributes'];
    expect($attributes['job_name'])->toBe('QueueTelemetrySuccessJob')
        ->and($attributes['connection'])->toBe('sync')
        ->and($attributes['outcome'])->toBe('success')
        ->and($attributes)->toHaveKeys(['environment', 'service_name', 'queue']);

    expect($durations[0]['value'])->toBeFloat()->toBeGreaterThanOrEqual(0.0);

    $spanAttributes = $recorder->mergedSpanAttributes();
    expect($spanAttributes['ixora.queue.job_name'])->toBe('QueueTelemetrySuccessJob')
        ->and($spanAttributes['ixora.queue.outcome'])->toBe('success')
        ->and($recorder->spanEndCalls)->toBe(0)
        ->and($recorder->spanErrorCalls)->toBe(0);

    expect($recorder->netUpDownCounter('ixora.queue.job.active'))->toBe(0);
});

// 2. Failed job.
test('a failed job is counted once with a failed outcome, preserves the original exception, and marks the active span as an error', function () {
    $recorder = fakeQueueTelemetry();

    expect(fn () => QueueTelemetryFailingJob::dispatch())->toThrow(RuntimeException::class, 'job exploded');

    $totals = queueJobTotalCalls($recorder);
    $durations = queueDurationCalls($recorder);

    expect($totals)->toHaveCount(1)
        ->and($durations)->toHaveCount(1);

    $attributes = $totals[0]['attributes'];
    expect($attributes['job_name'])->toBe('QueueTelemetryFailingJob')
        ->and($attributes['outcome'])->toBe('failed');

    expect($recorder->spanErrorCalls)->toBe(1)
        ->and($recorder->spanEndCalls)->toBe(0)
        // The exception is already recorded onto the active span by the
        // existing queue auto-instrumentation — not duplicated here.
        ->and($recorder->spanExceptions)->toBe([]);

    expect($recorder->netUpDownCounter('ixora.queue.job.active'))->toBe(0);
});

// 3. Retried/released job — exercised via direct event dispatch (sync has
// no release/retry concept, see file docblock).
test('a job released after an exception is counted once with a retried outcome and no double-counting', function () {
    $recorder = fakeQueueTelemetry();
    app(QueueExecutionTelemetry::class); // resolve so the listener bindings use the fakes

    $job = mockQueueJob('App\\Jobs\\Example\\RetryableJob', 'default', 1);
    $exception = new RuntimeException('will retry');

    Event::dispatch(new JobProcessing('database', $job));
    Event::dispatch(new JobExceptionOccurred('database', $job, $exception));
    Event::dispatch(new JobReleasedAfterException('database', $job, 5));

    $totals = queueJobTotalCalls($recorder);
    expect($totals)->toHaveCount(1);
    expect($totals[0]['attributes']['outcome'])->toBe('retried');

    expect($recorder->netUpDownCounter('ixora.queue.job.active'))->toBe(0);

    // The exception object seen by JobExceptionOccurred is now correlated
    // with the job's context for QueueErrorContextLogTap (Part 9).
    expect(app(QueueExecutionTelemetry::class)->contextForException($exception))
        ->not->toBeNull();
});

test('a job voluntarily released mid-handle (JobProcessed, isReleased() true) is counted once with a released outcome', function () {
    $recorder = fakeQueueTelemetry();

    $job = mockQueueJob('App\\Jobs\\Example\\SelfReleasingJob');
    $job->shouldReceive('isReleased')->andReturn(true);

    Event::dispatch(new JobProcessing('database', $job));
    Event::dispatch(new JobProcessed('database', $job));

    $totals = queueJobTotalCalls($recorder);
    expect($totals)->toHaveCount(1);
    expect($totals[0]['attributes']['outcome'])->toBe('released');
});

// 4. Sync queue — one execution count, no duplicate instrumentation.
test('dispatching two jobs on the sync connection counts exactly two attempts, never doubled', function () {
    $recorder = fakeQueueTelemetry();

    QueueTelemetrySuccessJob::dispatch();
    QueueTelemetrySuccessJob::dispatch();

    expect(queueJobTotalCalls($recorder))->toHaveCount(2)
        ->and(queueDurationCalls($recorder))->toHaveCount(2);
});

// 5. Unknown/missing queue metadata.
test('a job with unresolvable metadata records bounded fallback values without throwing', function () {
    $recorder = fakeQueueTelemetry();

    $job = Mockery::mock(QueueJob::class);
    $job->shouldReceive('resolveQueuedJobClass')->andThrow(new RuntimeException('malformed payload'));
    $job->shouldReceive('getQueue')->andReturn(null);
    $job->shouldReceive('attempts')->andThrow(new RuntimeException('no attempts tracking'));
    $job->shouldReceive('isReleased')->andReturn(false);
    $job->shouldReceive('payload')->andReturn([]);

    Event::dispatch(new JobProcessing('database', $job));
    Event::dispatch(new JobProcessed('database', $job));

    $totals = queueJobTotalCalls($recorder);
    expect($totals)->toHaveCount(1);

    $attributes = $totals[0]['attributes'];
    expect($attributes['job_name'])->toBe(QueueJobNormalizer::UNKNOWN)
        ->and($attributes['queue'])->toBe('default')
        ->and($attributes['outcome'])->toBe('success');
});

// 6. Cardinality safety.
test('no job ID, UUID, or serialized payload ever appears in queue metric labels or span attributes', function () {
    $recorder = fakeQueueTelemetry();

    QueueTelemetrySuccessJob::dispatch();

    $totals = queueJobTotalCalls($recorder);
    $serialized = json_encode($totals[0]['attributes']).json_encode($recorder->mergedSpanAttributes());

    expect($serialized)
        ->not->toContain('uuid')
        ->not->toContain('"id"')
        ->not->toContain('displayName');

    expect($totals[0]['attributes'])->not->toHaveKeys(['job_id', 'uuid', 'payload', 'user_id', 'schedule_id', 'device_id', 'trace_id', 'span_id', 'exception_message', 'attempt']);
});

// 7. Telemetry failure. Mirrors HttpRequestTelemetryMiddlewareTest's
// "broken Tracer" scenario (Phase 7B.1, Part 8) — Tracer::activeSpan() is
// exercised from inside QueueExecutionTelemetry::recordTerminal()'s own
// try/catch (safely()), the code path this class actually guards. Like
// HttpRequestTelemetry, Meter::counter()/histogram()/upDownCounter() run
// once in the constructor and are not wrapped — consistent with the
// established Phase 7B.1 precedent, since the real Meter implementation's
// instrument-creation is local/synchronous and not expected to fail.
test('a broken Tracer never fails the job, and the job still executes and records its metrics', function () {
    $recorder = fakeQueueTelemetry();
    app()->bind(Tracer::class, fn () => new class implements Tracer
    {
        public function startSpan(string $name, array $attributes = []): Span
        {
            throw new RuntimeException('tracer exploded');
        }

        public function activeSpan(): Span
        {
            throw new RuntimeException('tracer exploded');
        }

        public function currentContext(): ?TraceContext
        {
            return null;
        }

        public function currentTraceId(): ?string
        {
            return null;
        }

        public function currentSpanId(): ?string
        {
            return null;
        }
    });
    app()->forgetInstance(QueueExecutionTelemetry::class);

    $executed = false;
    Event::listen(JobProcessed::class, function () use (&$executed) {
        $executed = true;
    });

    QueueTelemetrySuccessJob::dispatch();

    expect($executed)->toBeTrue();

    // The counter/histogram add() calls happen before the broken
    // Tracer::activeSpan() call inside recordTerminal() — so metrics are
    // still recorded even though span enrichment failed.
    expect(queueJobTotalCalls($recorder))->toHaveCount(1);
});

// 8. Long-running worker safety.
test('lifecycle state is removed after every job, leaving no stale state for the next job', function () {
    $recorder = fakeQueueTelemetry();
    $telemetry = app(QueueExecutionTelemetry::class);

    QueueTelemetrySuccessJob::dispatch();
    expect($telemetry->currentContext())->toBeNull();

    try {
        QueueTelemetryFailingJob::dispatch();
    } catch (RuntimeException) {
        // expected — see scenario 2.
    }
    expect($telemetry->currentContext())->toBeNull();

    QueueTelemetrySuccessJob::dispatch();
    expect($telemetry->currentContext())->toBeNull();

    // Three attempts, three active-gauge increments and three decrements —
    // never accumulating across jobs.
    expect(queueJobTotalCalls($recorder))->toHaveCount(3)
        ->and($recorder->netUpDownCounter('ixora.queue.job.active'))->toBe(0);
});

test('a job that dispatches another job synchronously does not cross-contaminate either job\'s context', function () {
    $recorder = fakeQueueTelemetry();

    QueueTelemetryOuterJob::dispatch();

    $totals = queueJobTotalCalls($recorder);
    expect($totals)->toHaveCount(2);

    $names = array_map(fn (array $call) => $call['attributes']['job_name'], $totals);
    expect($names)->toContain('QueueTelemetrySuccessJob')
        ->and($names)->toContain('QueueTelemetryOuterJob');

    expect(app(QueueExecutionTelemetry::class)->currentContext())->toBeNull();
});
