<?php

declare(strict_types=1);

namespace App\Telemetry\Queue;

use App\Telemetry\Contracts\Counter;
use App\Telemetry\Contracts\Histogram;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Contracts\UpDownCounter;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Throwable;
use WeakMap;

/**
 * The Ixora-specific layer on top of the queue producer/consumer spans
 * opentelemetry-auto-laravel already starts (backend-queue-console-
 * instrumentation.md §"Queue auto-instrumentation review"). Records the
 * two minimum queue metrics (Part 4), enriches the active span with safe,
 * bounded attributes (Part 6), and exposes the currently-processing job's
 * normalized context to QueueErrorContextLogTap — never creates a span,
 * never alters job/worker behavior, never throws.
 *
 * Registered as a Laravel queue-event listener (Part 7) — JobProcessing,
 * JobProcessed, JobFailed, JobReleasedAfterException, JobTimedOut, and
 * JobExceptionOccurred (log-correlation only, see below) — the minimum set
 * that can express success/failed/released/retried/timed_out without
 * double-counting a single attempt (see the class-level table in
 * backend-queue-console-instrumentation.md §"Queue lifecycle integration").
 *
 * Lifecycle-state safety (Part 7): a job's normalized context + start time
 * is pushed onto an in-memory stack on JobProcessing and popped by exactly
 * one terminal event per attempt. A stack (not a single slot) correctly
 * handles a job that itself dispatches another job synchronously
 * (nested `sync` execution) without cross-contaminating the outer job's
 * context. Every push is matched by exactly one pop; a terminal event that
 * finds the stack already empty (e.g. JobProcessed firing after JobFailed
 * already popped the frame for a mid-handle `$this->fail()` call — see the
 * doc's §"Queue lifecycle integration" for why both fire in that case) is a
 * safe no-op, never a double record. This also bounds worst-case memory in
 * a long-running worker: the stack only ever grows with genuine call-stack
 * nesting, never accumulates across unrelated jobs over time.
 *
 * Log correlation (Part 9): QueueErrorContextLogTap needs the normalized
 * context of the job a *specific* exception belongs to, at the moment
 * Laravel actually logs it — which, for a real queue worker, happens in
 * Illuminate\Queue\Worker::runJob()'s catch block, strictly after
 * process() (and therefore this class's JobFailed/JobReleasedAfterException
 * listener, which already popped the stack) has returned. An ambient
 * "current job" read at log time would already be null, or worse, would
 * show a *different*, unrelated job if a long-running worker had already
 * moved on. Instead, JobExceptionOccurred — dispatched while the exception
 * is still being handled, before any retry/fail branching — associates the
 * exception object itself with its job's QueueContext in a WeakMap.
 * contextForException() then looks up that exact object, however long it
 * takes the exception to actually reach a log call and regardless of how
 * many other jobs ran in between. WeakMap entries are collected
 * automatically once the exception is no longer referenced elsewhere, so
 * this cannot leak or grow unbounded in a long-running worker.
 *
 * Consumes only App\Telemetry\Contracts\{Tracer,Meter,Counter,Histogram,
 * UpDownCounter} — no OpenTelemetry SDK import anywhere in this class or
 * elsewhere in app/Telemetry/Queue.
 */
final class QueueExecutionTelemetry
{
    private const METRIC_JOB_TOTAL = 'ixora.queue.job.total';

    private const METRIC_DURATION = 'ixora.queue.job.duration';

    private const METRIC_ACTIVE = 'ixora.queue.job.active';

    /** Matches ixora.http.server.duration's platform-wide unit choice. */
    private const DURATION_UNIT = 'ms';

    private readonly Counter $jobTotal;

    private readonly Histogram $duration;

    private readonly UpDownCounter $active;

    /** @var list<array{context: QueueContext, startedAt: int}> */
    private array $stack = [];

    /** @var WeakMap<Throwable, QueueContext> */
    private readonly WeakMap $exceptionContexts;

    public function __construct(
        private readonly Tracer $tracer,
        Meter $meter,
        private readonly QueueJobNormalizer $normalizer,
        private readonly string $environment,
        private readonly string $serviceName,
    ) {
        $this->jobTotal = $meter->counter(
            self::METRIC_JOB_TOTAL,
            unit: '{job}',
            description: 'Total queue job execution attempts, labeled by queue, connection, job name, and outcome.',
        );

        $this->duration = $meter->histogram(
            self::METRIC_DURATION,
            unit: self::DURATION_UNIT,
            description: 'Queue job execution duration in milliseconds, labeled by queue, connection, job name, and outcome.',
        );

        $this->active = $meter->upDownCounter(
            self::METRIC_ACTIVE,
            unit: '{job}',
            description: 'Currently executing queue jobs, labeled by queue, connection, and job name.',
        );

        $this->exceptionContexts = new WeakMap;
    }

    public function jobProcessing(JobProcessing $event): void
    {
        $this->safely(function () use ($event) {
            $context = $this->contextFor($event->connectionName, $event->job);

            $this->stack[] = ['context' => $context, 'startedAt' => hrtime(true)];

            $this->active->add(1, $this->activeLabels($context));
        });
    }

    public function jobProcessed(JobProcessed $event): void
    {
        $this->safely(fn () => $this->recordTerminal(
            $event->job->isReleased() ? QueueOutcome::Released : QueueOutcome::Success,
        ));
    }

    /**
     * Not used for metrics (JobFailed already covers the "failed" outcome
     * definitively) — only associates the exception with its job's context
     * for QueueErrorContextLogTap. See the class docblock §"Log correlation".
     */
    public function jobExceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->safely(function () use ($event) {
            $context = $this->currentContext();

            if ($context !== null) {
                $this->exceptionContexts[$event->exception] = $context;
            }
        });
    }

    public function jobFailed(JobFailed $event): void
    {
        $this->safely(function () use ($event) {
            if ($event->exception instanceof Throwable) {
                $context = $this->currentContext();

                if ($context !== null) {
                    $this->exceptionContexts[$event->exception] = $context;
                }
            }

            $this->recordTerminal(QueueOutcome::Failed);
        });
    }

    public function jobReleasedAfterException(JobReleasedAfterException $event): void
    {
        $this->safely(fn () => $this->recordTerminal(QueueOutcome::Retried));
    }

    /**
     * Best-effort only: this event is dispatched from a SIGALRM handler
     * that calls exit() immediately afterward (Illuminate\Queue\Worker::
     * registerTimeoutHandler()) — see backend-queue-console-instrumentation.md
     * §"Known limitations" for why this path cannot be exercised by the
     * automated test suite and why export reliability here depends on the
     * Phase 7A shutdown-flush mechanism rather than this class.
     */
    public function jobTimedOut(JobTimedOut $event): void
    {
        $this->safely(fn () => $this->recordTerminal(QueueOutcome::TimedOut));
    }

    /**
     * The normalized context of the job currently being processed on this
     * stack (deepest/most recent), or null when no job is in flight. Read
     * by QueueErrorContextLogTap — never mutates the stack.
     */
    public function currentContext(): ?QueueContext
    {
        if ($this->stack === []) {
            return null;
        }

        return $this->stack[array_key_last($this->stack)]['context'];
    }

    /**
     * The normalized context of the job that a specific exception occurred
     * in, or null if this exception was never seen by JobExceptionOccurred
     * or JobFailed. See the class docblock §"Log correlation".
     */
    public function contextForException(Throwable $exception): ?QueueContext
    {
        return $this->exceptionContexts[$exception] ?? null;
    }

    private function recordTerminal(QueueOutcome $outcome): void
    {
        $frame = array_pop($this->stack);

        if ($frame === null) {
            // No matching JobProcessing frame — already recorded by an
            // earlier terminal event for this same attempt (see class
            // docblock), or a missing start event. Either way, recording
            // again here would double-count; skip safely.
            return;
        }

        $context = $frame['context'];
        $durationMs = (hrtime(true) - $frame['startedAt']) / 1_000_000;

        $labels = [
            'environment' => $this->environment,
            'service_name' => $this->serviceName,
            'queue' => $context->queue,
            'connection' => $context->connection,
            'job_name' => $context->jobName,
            'outcome' => $outcome->value,
        ];

        $this->jobTotal->add(1, $labels);
        $this->duration->record($durationMs, $labels);
        $this->active->add(-1, $this->activeLabels($context));

        $span = $this->tracer->activeSpan();
        $span->setAttributes([
            'ixora.queue.job_name' => $context->jobName,
            'ixora.queue.outcome' => $outcome->value,
            'ixora.queue.connection' => $context->connection,
            'ixora.queue.attempt' => $context->attempt,
        ]);

        if ($outcome === QueueOutcome::Failed || $outcome === QueueOutcome::TimedOut) {
            // The underlying exception is already recorded onto the active
            // span by the existing queue auto-instrumentation once it
            // finishes propagating out of Worker::process()/SyncQueue::push()
            // (Part 1 review) — not duplicated here, exactly like
            // HttpRequestTelemetry does for 5xx responses.
            $span->setError();
        }
    }

    private function contextFor(string $connectionName, Job $job): QueueContext
    {
        $attempt = 0;

        try {
            $attempt = $job->attempts();
        } catch (Throwable) {
            // Bounded fallback — never let a custom Job implementation's
            // attempts() failure escape telemetry.
        }

        return new QueueContext(
            connection: $connectionName,
            queue: $job->getQueue() ?: 'default',
            jobName: $this->normalizer->normalize($job),
            attempt: $attempt,
        );
    }

    /**
     * @return array<string, string>
     */
    private function activeLabels(QueueContext $context): array
    {
        return [
            'environment' => $this->environment,
            'service_name' => $this->serviceName,
            'queue' => $context->queue,
            'connection' => $context->connection,
            'job_name' => $context->jobName,
        ];
    }

    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable) {
            // Intentionally swallowed — telemetry must never affect job
            // execution, retry count, release, or failed-job recording
            // (telemetry-availability-policy.md).
        }
    }
}
