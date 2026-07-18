<?php

declare(strict_types=1);

namespace App\Telemetry\Scheduler;

use App\Telemetry\Contracts\Counter;
use App\Telemetry\Contracts\Histogram;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use DateTimeZone;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Throwable;
use WeakMap;

/**
 * Generic Laravel Scheduler telemetry (Phase 7B.3, Level 1 only — Level 2
 * "Ixora Domain Scheduling" is explicitly deferred, see backend-generic-
 * scheduler-instrumentation.md §"Scope"). Records the two minimum
 * scheduler metrics (Part 5), creates one boundary span per executed event
 * (Part 7 Strategy B — no existing span represents this boundary, see
 * §"Scheduler auto-instrumentation review"), and exposes normalized,
 * bounded context to SchedulerErrorContextLogTap via exception-identity
 * correlation — never alters due-event selection, mutex/overlap behavior,
 * event callbacks, exit codes, or exceptions, never throws.
 *
 * Verified findings this class's design depends on (backend-generic-
 * scheduler-instrumentation.md has the full review):
 *
 * - opentelemetry-auto-laravel ships no Scheduling hook at all (no span is
 *   ever created for a scheduled event boundary by auto-instrumentation) —
 *   Strategy B (create one) is therefore the only option, not a choice
 *   made over Strategy A.
 * - A *command* event (Schedule::command()/exec()) always runs as a
 *   genuinely separate OS process — Illuminate\Console\Scheduling\Event::
 *   execute() shells out via Symfony Process::fromShellCommandline() for
 *   both foreground (blocking) and background (fire-and-forget with `&`)
 *   execution. There is no framework-level trace-context propagation into
 *   that child process (no traceparent injected onto the command line or
 *   environment) — a span the auto Console hook starts inside that child
 *   process is therefore always a new, disconnected trace, never a child
 *   of the span this class starts here. This is a real, honestly
 *   documented limitation (Part 8), not something this phase works around
 *   by modifying Illuminate\Console\Scheduling\Event (forbidden — Scheduler
 *   business logic).
 * - A *callback* event (Schedule::call()/job()) runs in-process via
 *   Container::call() — no subprocess, cannot run in the background
 *   (CallbackEvent::runInBackground() throws).
 * - ScheduledTaskStarting fires before Event::run() — i.e. before the
 *   mutex/overlap check inside it. Illuminate\Console\Scheduling\
 *   ScheduleRunCommand::runEvent() does *not* dispatch ScheduledTaskSkipped
 *   when an event is skipped for overlapping — Event::run() returns
 *   normally (having done nothing) and ScheduledTaskFinished still fires.
 *   The only reliable signal is the public Event::$skippedBecauseOverlapping
 *   flag, read here at ScheduledTaskFinished time (Part 9).
 * - Double-fire for a failing foreground command: ScheduleRunCommand::
 *   runEvent() dispatches ScheduledTaskFinished unconditionally once
 *   Event::run() returns, *then* — only for a foreground command with a
 *   non-zero exit code — throws a brand new synthetic Exception and
 *   dispatches ScheduledTaskFailed for the *same* event. Recording a
 *   terminal metric from both would double-count (Part 17); see
 *   scheduledTaskFailed()'s docblock for how this is resolved.
 * - ScheduledBackgroundTaskFinished is dispatched by `artisan
 *   schedule:finish`, always spawned as its *own* separate PHP process
 *   (Illuminate\Console\Scheduling\CommandBuilder::buildBackgroundCommand())
 *   — this class's in-memory stack/WeakMaps in that process start empty.
 *   See that handler's docblock for why only best-effort span enrichment,
 *   never a metric, happens there.
 *
 * Execution-state strategy (Part 11): a stack, not a single slot — mirrors
 * App\Telemetry\Queue\QueueExecutionTelemetry. Illuminate\Console\
 * Scheduling\ScheduleRunCommand runs due events strictly sequentially
 * (`foreach`), so nesting is not the normal case, but a scheduled
 * closure/job callback executes in-process and could in principle trigger
 * another scheduled dispatch (e.g. by calling Artisan::call('schedule:run')
 * itself) — a stack handles that correctly without cross-contaminating an
 * outer event's context, at negligible extra cost over a single slot.
 * Every push is matched by exactly one pop (see the two terminal handlers)
 * — long-running `schedule:work` cannot accumulate stale frames.
 *
 * $taskContexts (WeakMap<Event, SchedulerContext>) holds each scheduled
 * event's most recently computed context, keyed by the Event/CallbackEvent
 * object itself. Illuminate\Console\Scheduling\Schedule holds these Event
 * objects for the lifetime of the process, so this is bounded by the
 * number of distinct ->command()/->call()/->job() registrations in the
 * application's schedule — never unbounded in a long-running
 * `schedule:work` process. It exists solely to let scheduledTaskFailed()
 * recover the context Finished already computed for the double-fire case
 * above, without re-introducing an ambient "current event" a later,
 * unrelated exception could incorrectly pick up (Part 10's log-isolation
 * requirement — contrast with ConsoleCommandTelemetry::currentContext(),
 * which is safe only because a console process runs at most one top-level
 * command).
 *
 * $exceptionContexts (WeakMap<Throwable, SchedulerContext>) is the log tap's
 * only read path — SchedulerErrorContextLogTap looks up a *specific*
 * exception object, never "whatever ran most recently", exactly like
 * QueueExecutionTelemetry::contextForException().
 *
 * ixora.scheduler.event.active is intentionally not implemented — Part 5
 * requires proof that "foreground and background executions are both
 * handled" and "process termination does not leave an avoidable stale
 * gauge" before adding it. A background event's start (ScheduledTaskStarting,
 * in the `schedule:run` process) and its real completion
 * (ScheduledBackgroundTaskFinished, in a separate `schedule:finish`
 * process, per the finding above) cannot symmetrically increment/decrement
 * the same in-memory gauge — doing so would either never decrement (stale
 * gauge in `schedule:run`, which exits right after launching a background
 * event) or require inventing cross-process state this phase's Failure
 * Policy and Part 8 explicitly forbid. See backend-generic-scheduler-
 * instrumentation.md §"Known limitations".
 *
 * Scheduled-versus-manual Console invocation_source (Part 6) is also
 * intentionally not implemented in this phase — see
 * backend-generic-scheduler-instrumentation.md §"Scheduled versus manual
 * commands" for the full, evidence-based reasoning (the only framework
 * channel that survives the command-event process boundary is Laravel's
 * global Context/`__LARAVEL_CONTEXT`-env mechanism, which also propagates
 * into queued-job payloads and ambient log context — piggybacking a
 * Scheduler marker on it risks exactly the cross-signal leakage Part 10
 * forbids). App\Telemetry\Console\ConsoleCommandTelemetry is completely
 * unmodified by this phase.
 *
 * Consumes only App\Telemetry\Contracts\{Tracer,Meter,Counter,Histogram,Span} —
 * no OpenTelemetry SDK import anywhere in this class or elsewhere in
 * app/Telemetry/Scheduler.
 */
final class SchedulerExecutionTelemetry
{
    private const METRIC_EVENT_TOTAL = 'ixora.scheduler.event.total';

    private const METRIC_DURATION = 'ixora.scheduler.event.duration';

    /** Matches ixora.http.server.duration's platform-wide unit choice. */
    private const DURATION_UNIT = 'ms';

    private readonly Counter $eventTotal;

    private readonly Histogram $duration;

    /** @var list<array{task: Event, context: SchedulerContext, startedAt: int, span: Span}> */
    private array $stack = [];

    /** @var WeakMap<Event, SchedulerContext> */
    private readonly WeakMap $taskContexts;

    /** @var WeakMap<Throwable, SchedulerContext> */
    private readonly WeakMap $exceptionContexts;

    public function __construct(
        private readonly Tracer $tracer,
        Meter $meter,
        private readonly SchedulerEventNormalizer $normalizer,
        private readonly string $environment,
        private readonly string $serviceName,
    ) {
        $this->eventTotal = $meter->counter(
            self::METRIC_EVENT_TOTAL,
            unit: '{event}',
            description: 'Total scheduled-event executions, labeled by event name, type, execution mode, and outcome.',
        );

        $this->duration = $meter->histogram(
            self::METRIC_DURATION,
            unit: self::DURATION_UNIT,
            description: 'Scheduled-event duration in milliseconds, labeled by event name, type, execution mode, and outcome.',
        );

        $this->taskContexts = new WeakMap;
        $this->exceptionContexts = new WeakMap;
    }

    public function scheduledTaskStarting(ScheduledTaskStarting $event): void
    {
        $this->safely(function () use ($event) {
            $task = $event->task;
            $context = $this->buildContext($task);

            // Span creation failure must not prevent this frame from being
            // pushed — a broken Tracer would otherwise silently disable
            // *metrics* too (no frame for the terminal handler to pop),
            // which is a stricter failure mode than the Tracer alone
            // warrants (Part 12: only span enrichment may be lost).
            $span = $this->startBoundarySpan($context);

            $this->stack[] = [
                'task' => $task,
                'context' => $context,
                'startedAt' => hrtime(true),
                'span' => $span,
            ];

            $this->taskContexts[$task] = $context;
        });
    }

    private function startBoundarySpan(SchedulerContext $context): Span
    {
        try {
            $span = $this->tracer->startSpan('scheduler.event '.$context->eventName, [
                'ixora.scheduler.event_name' => $context->eventName,
                'ixora.scheduler.event_type' => $context->eventType->value,
                'ixora.scheduler.execution_mode' => $context->executionMode->value,
                'ixora.scheduler.scheduled' => true,
            ]);

            if ($context->expression !== null) {
                $span->setAttribute('ixora.scheduler.expression', $context->expression);
            }

            if ($context->timezone !== null) {
                $span->setAttribute('ixora.scheduler.timezone', $context->timezone);
            }

            return $span;
        } catch (Throwable) {
            return $this->inertSpan();
        }
    }

    /**
     * A local, dependency-rule-safe stand-in for a span — App\Telemetry\
     * Noop\NoopSpan is deliberately not reused here: the same restatement
     * test that guards Queue/Console (tests/Unit/Telemetry/Scheduler/
     * SchedulerTelemetryDependencyRuleTest.php) forbids this module from
     * importing any concrete OpenTelemetry *or* Noop implementation, only
     * the Contracts. This anonymous class implements nothing but the
     * Span contract itself.
     */
    private function inertSpan(): Span
    {
        return new class implements Span
        {
            public function setAttribute(string $key, $value): static
            {
                return $this;
            }

            public function setAttributes(array $attributes): static
            {
                return $this;
            }

            public function addEvent(string $name, array $attributes = []): static
            {
                return $this;
            }

            public function recordException(Throwable $exception): static
            {
                return $this;
            }

            public function setError(?string $description = null): static
            {
                return $this;
            }

            public function end(): void {}
        };
    }

    public function scheduledTaskFinished(ScheduledTaskFinished $event): void
    {
        $this->safely(function () use ($event) {
            $task = $event->task;
            $frame = array_pop($this->stack);

            if ($frame === null) {
                // No matching ScheduledTaskStarting frame — never double-count.
                return;
            }

            $outcome = $this->classifyOutcome($task, $frame['context']);

            $this->recordTerminal($frame, $outcome, $task->exitCode);
        });
    }

    /**
     * Handles both genuine in-flight failures (before()-callback exception,
     * or an exception thrown inside a scheduled closure/job — Finished
     * never fired for these) and the ScheduleRunCommand double-fire for a
     * failing foreground command (Finished already fired and already
     * recorded the terminal metric — see class docblock). Either way, this
     * exception is correlated to Scheduler context for
     * SchedulerErrorContextLogTap, since Illuminate\Contracts\Debug\
     * ExceptionHandler::report() logs it in both cases.
     */
    public function scheduledTaskFailed(ScheduledTaskFailed $event): void
    {
        $this->safely(function () use ($event) {
            $task = $event->task;
            $frame = array_pop($this->stack);

            if ($frame !== null) {
                $this->recordTerminal($frame, SchedulerOutcome::Failed, $task->exitCode, $event->exception);

                return;
            }

            $context = $this->taskContexts[$task] ?? null;

            if ($context !== null) {
                $this->exceptionContexts[$event->exception] = $context;
            }
        });
    }

    /**
     * Fires for a paused schedule, a failed filtersPass()/rejects()
     * callback, or a lost one-server check — all *before* runEvent()/
     * ScheduledTaskStarting, so there is never a matching stack frame and
     * never a meaningful duration to record (Part 13 scenario 9). Metric
     * only, no routine log, and no dedicated boundary span since no
     * execution occurred — a lightweight event annotation on whatever
     * span is already active is enough to keep this trace-visible without
     * a second span lifecycle to manage (Part 10).
     */
    public function scheduledTaskSkipped(ScheduledTaskSkipped $event): void
    {
        $this->safely(function () use ($event) {
            $task = $event->task;
            $context = $this->buildContext($task)->withResult(SchedulerOutcome::Skipped);

            $this->eventTotal->add(1, $this->metricLabels($context));

            $this->tracer->activeSpan()->addEvent('scheduler.event.skipped', [
                'ixora.scheduler.event_name' => $context->eventName,
                'ixora.scheduler.event_type' => $context->eventType->value,
            ]);

            $this->taskContexts[$task] = $context;
        });
    }

    /**
     * Best-effort span enrichment only — see class docblock for why this
     * event is always observed in a separate PHP process with no shared
     * in-memory state, and why no metric is ever recorded here (it would
     * double-count the ixora.scheduler.event.total/duration entry already
     * recorded, with outcome=background_completed, by
     * scheduledTaskFinished() in the original `schedule:run` process).
     */
    public function scheduledBackgroundTaskFinished(ScheduledBackgroundTaskFinished $event): void
    {
        $this->safely(function () use ($event) {
            $task = $event->task;
            $context = $this->buildContext($task);

            $outcome = match (true) {
                $task->exitCode === null => SchedulerOutcome::Unknown,
                $task->exitCode === 0 => SchedulerOutcome::Success,
                default => SchedulerOutcome::Failed,
            };

            $span = $this->tracer->activeSpan();
            $span->setAttributes([
                'ixora.scheduler.event_name' => $context->eventName,
                'ixora.scheduler.event_type' => $context->eventType->value,
                'ixora.scheduler.execution_mode' => SchedulerExecutionMode::Background->value,
            ]);

            if ($task->exitCode !== null) {
                $span->setAttribute('ixora.scheduler.exit_code', $task->exitCode);
            }

            if ($outcome === SchedulerOutcome::Failed) {
                $span->setError();
            }
        });
    }

    /**
     * The Scheduler context a specific exception belongs to, or null if
     * this exception was never seen by scheduledTaskFailed(). Read by
     * SchedulerErrorContextLogTap — see class docblock §"Log correlation".
     */
    public function contextForException(Throwable $exception): ?SchedulerContext
    {
        return $this->exceptionContexts[$exception] ?? null;
    }

    /**
     * The number of scheduled events currently on the in-memory stack —
     * exposes no context data (unlike ConsoleCommandTelemetry::
     * currentContext()/QueueExecutionTelemetry::currentContext(), neither
     * of which this class provides, by design — see class docblock
     * §"Log correlation"). Exists only so tests can verify Part 11's
     * execution-state cleanup guarantee (every push is matched by exactly
     * one pop) without reflection.
     */
    public function activeExecutionCount(): int
    {
        return count($this->stack);
    }

    private function recordTerminal(array $frame, SchedulerOutcome $outcome, ?int $exitCode, ?Throwable $exception = null): void
    {
        /** @var Event $task */
        $task = $frame['task'];
        /** @var SchedulerContext $baseContext */
        $baseContext = $frame['context'];
        /** @var int $startedAt */
        $startedAt = $frame['startedAt'];
        /** @var Span $span */
        $span = $frame['span'];

        $context = $baseContext->withResult($outcome, $exitCode);
        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

        $this->eventTotal->add(1, $this->metricLabels($context));
        $this->duration->record($durationMs, $this->metricLabels($context));

        $span->setAttribute('ixora.scheduler.outcome', $outcome->value);

        if ($exitCode !== null) {
            $span->setAttribute('ixora.scheduler.exit_code', $exitCode);
        }

        if ($outcome === SchedulerOutcome::Failed) {
            $span->setError();

            if ($exception !== null) {
                $span->recordException($exception);
            }
        }

        $span->end();

        $this->taskContexts[$task] = $context;

        if ($exception !== null) {
            $this->exceptionContexts[$exception] = $context;
        }
    }

    private function classifyOutcome(Event $task, SchedulerContext $context): SchedulerOutcome
    {
        if ($task->skippedBecauseOverlapping) {
            return SchedulerOutcome::OverlapPrevented;
        }

        if ($context->executionMode === SchedulerExecutionMode::Background) {
            return SchedulerOutcome::BackgroundCompleted;
        }

        if ($task->exitCode === null) {
            return SchedulerOutcome::Unknown;
        }

        return $task->exitCode === 0 ? SchedulerOutcome::Success : SchedulerOutcome::Failed;
    }

    private function buildContext(Event $task): SchedulerContext
    {
        $type = $this->normalizer->type($task);
        $name = $this->normalizer->name($task, $type);

        return new SchedulerContext(
            eventName: $name,
            eventType: $type,
            executionMode: $task->runInBackground ? SchedulerExecutionMode::Background : SchedulerExecutionMode::Foreground,
            expression: $this->normalizeExpression($task->expression),
            timezone: $this->normalizeTimezone($task->timezone),
        );
    }

    private function normalizeExpression(mixed $expression): ?string
    {
        return is_string($expression) && $expression !== '' ? $expression : null;
    }

    private function normalizeTimezone(mixed $timezone): ?string
    {
        if ($timezone instanceof DateTimeZone) {
            return $timezone->getName();
        }

        return is_string($timezone) && $timezone !== '' ? $timezone : null;
    }

    /**
     * @return array<string, string>
     */
    private function metricLabels(SchedulerContext $context): array
    {
        return [
            'environment' => $this->environment,
            'service_name' => $this->serviceName,
            'event_name' => $context->eventName,
            'event_type' => $context->eventType->value,
            'execution_mode' => $context->executionMode->value,
            'outcome' => $context->outcome?->value ?? SchedulerOutcome::Unknown->value,
        ];
    }

    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable) {
            // Intentionally swallowed — telemetry must never affect
            // due-event selection, mutex/overlap behavior, event
            // execution, or exit codes (telemetry-availability-policy.md).
        }
    }
}
