<?php

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\Counter;
use App\Telemetry\Contracts\Histogram;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use App\Telemetry\Contracts\UpDownCounter;
use App\Telemetry\Noop\NoopTracer;
use App\Telemetry\Scheduler\SchedulerEventNormalizer;
use App\Telemetry\Scheduler\SchedulerExecutionTelemetry;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Event as EventFacade;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\Telemetry\RecordingMeter;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Telemetry\TelemetryRecorder;

/**
 * Phase 7B.3 — generic Laravel Scheduler telemetry (Level 1 only), exercised
 * through the real App\Telemetry\Scheduler\SchedulerExecutionTelemetry
 * listener exactly as it is registered in TelemetryServiceProvider.
 *
 * Real Illuminate\Console\Scheduling\Event/CallbackEvent instances are
 * built through Illuminate\Console\Scheduling\Schedule's own fluent API
 * (->command()/->call()/->job()/->exec()) — never mocked — then the
 * Illuminate\Console\Events\Scheduled* lifecycle events are dispatched
 * directly, exactly like ConsoleCommandTelemetryTest dispatches
 * CommandStarting/CommandFinished directly. This is necessary (not just a
 * style choice): Event::run()/CallbackEvent::execute() either shells out to
 * a real OS process (Symfony Process::fromShellCommandline()) or executes
 * arbitrary application callbacks — neither is something this suite should
 * trigger for real just to exercise a telemetry listener, and back_vibes
 * itself registers no Illuminate\Console\Scheduling\Schedule entries at all
 * today (verified — see backend-generic-scheduler-instrumentation.md
 * §"Scope"; its own domain scheduling runs through a plain Artisan command
 * loop, not this facade).
 */
final class SchedulerTelemetryTestJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void {}
}

function fakeSchedulerTelemetry(): TelemetryRecorder
{
    $recorder = new TelemetryRecorder;

    app()->bind(Tracer::class, fn () => new RecordingTracer($recorder));
    app()->bind(Meter::class, fn () => new RecordingMeter($recorder));
    app()->forgetInstance(SchedulerExecutionTelemetry::class);

    return $recorder;
}

/**
 * @return list<array{name: string, amount: int|float, attributes: array<string, mixed>}>
 */
function schedulerEventTotalCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->counterCalls,
        fn (array $call) => $call['name'] === 'ixora.scheduler.event.total',
    ));
}

/**
 * @return list<array{name: string, value: int|float, attributes: array<string, mixed>}>
 */
function schedulerDurationCalls(TelemetryRecorder $recorder): array
{
    return array_values(array_filter(
        $recorder->histogramCalls,
        fn (array $call) => $call['name'] === 'ixora.scheduler.event.duration',
    ));
}

function freshLaravelSchedule(): Schedule
{
    return new Schedule;
}

afterEach(function () {
    Mockery::close();
});

// 1. Successful foreground scheduled command.
test('a successful foreground scheduled command is counted once with a stable command event name and success outcome', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()->command('queue:prune-batches', ['--hours' => 48]);

    EventFacade::dispatch(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    EventFacade::dispatch(new ScheduledTaskFinished($task, 0.02));

    $totals = schedulerEventTotalCalls($recorder);
    $durations = schedulerDurationCalls($recorder);

    expect($totals)->toHaveCount(1)
        ->and($durations)->toHaveCount(1);

    $attributes = $totals[0]['attributes'];
    expect($attributes['event_name'])->toBe('command:queue:prune-batches')
        ->and($attributes['event_type'])->toBe('command')
        ->and($attributes['execution_mode'])->toBe('foreground')
        ->and($attributes['outcome'])->toBe('success')
        ->and($attributes)->toHaveKeys(['environment', 'service_name']);

    expect($durations[0]['value'])->toBeFloat()->toBeGreaterThanOrEqual(0.0);

    $spanAttributes = $recorder->mergedSpanAttributes();
    expect($spanAttributes['ixora.scheduler.event_name'])->toBe('command:queue:prune-batches')
        ->and($spanAttributes['ixora.scheduler.event_type'])->toBe('command')
        ->and($spanAttributes['ixora.scheduler.outcome'])->toBe('success')
        ->and($spanAttributes['ixora.scheduler.exit_code'])->toBe(0)
        ->and($recorder->spanEndCalls)->toBe(1)
        ->and($recorder->spanErrorCalls)->toBe(0);

    expect($recorder->startSpanCalls)->toHaveCount(1);
    expect($recorder->startSpanCalls[0]['name'])->toBe('scheduler.event command:queue:prune-batches');

    expect(app(SchedulerExecutionTelemetry::class)->activeExecutionCount())->toBe(0);
});

// 2. Failed foreground scheduled command.
test('a failed foreground scheduled command (non-zero exit code) is counted once with a failed outcome and marks its span as an error', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()->command('migrate');

    EventFacade::dispatch(new ScheduledTaskStarting($task));
    $task->exitCode = 1;
    // Mirrors Illuminate\Console\Scheduling\ScheduleRunCommand::runEvent():
    // ScheduledTaskFinished always fires first for a foreground command,
    // even with a non-zero exit code.
    EventFacade::dispatch(new ScheduledTaskFinished($task, 0.05));

    $totals = schedulerEventTotalCalls($recorder);
    expect($totals)->toHaveCount(1);
    expect($totals[0]['attributes']['outcome'])->toBe('failed');

    expect($recorder->spanErrorCalls)->toBe(1)
        ->and($recorder->spanEndCalls)->toBe(1)
        ->and($recorder->spanExceptions)->toBe([]);

    // ScheduleRunCommand then throws a *synthetic* Exception and dispatches
    // ScheduledTaskFailed for this same event — the stack frame is already
    // gone (popped above), so no second metric is recorded, but the
    // exception is still correlated for SchedulerErrorContextLogTap.
    $syntheticException = new Exception("Scheduled command [{$task->command}] failed with exit code [1].");
    EventFacade::dispatch(new ScheduledTaskFailed($task, $syntheticException));

    expect(schedulerEventTotalCalls($recorder))->toHaveCount(1);
    expect($recorder->spanErrorCalls)->toBe(1);

    $telemetry = app(SchedulerExecutionTelemetry::class);
    expect($telemetry->contextForException($syntheticException))->not->toBeNull();
    expect($telemetry->contextForException($syntheticException)->outcome->value)->toBe('failed');
    expect($telemetry->activeExecutionCount())->toBe(0);
});

test('a scheduled closure that throws before ScheduledTaskFinished ever fires is still recorded exactly once as failed', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()->call(function () {
        throw new RuntimeException('closure exploded');
    })->name('explode-task');

    EventFacade::dispatch(new ScheduledTaskStarting($task));

    $exception = new RuntimeException('closure exploded');
    $task->exitCode = 1;
    // No ScheduledTaskFinished is ever dispatched for this attempt — see
    // CallbackEvent::run()/class docblock.
    EventFacade::dispatch(new ScheduledTaskFailed($task, $exception));

    $totals = schedulerEventTotalCalls($recorder);
    expect($totals)->toHaveCount(1);
    expect($totals[0]['attributes']['outcome'])->toBe('failed')
        ->and($totals[0]['attributes']['event_type'])->toBe('callback');

    expect($recorder->spanErrorCalls)->toBe(1)
        ->and($recorder->spanExceptions)->toHaveCount(1)
        ->and($recorder->spanExceptions[0])->toBe($exception)
        ->and($recorder->spanEndCalls)->toBe(1);

    expect(app(SchedulerExecutionTelemetry::class)->contextForException($exception))->not->toBeNull();
    expect(app(SchedulerExecutionTelemetry::class)->activeExecutionCount())->toBe(0);
});

// 3. Scheduled closure/callback — bounded name, no source path, no args.
test('a scheduled closure with no name records the bounded "closure" fallback, never a file path or source code', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()->call(function () {
        return true;
    });

    EventFacade::dispatch(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    EventFacade::dispatch(new ScheduledTaskFinished($task, 0.0));

    $totals = schedulerEventTotalCalls($recorder);
    expect($totals)->toHaveCount(1);
    expect($totals[0]['attributes']['event_name'])->toBe('closure')
        ->and($totals[0]['attributes']['event_type'])->toBe('closure');

    $serialized = json_encode($totals[0]['attributes']).json_encode($recorder->mergedSpanAttributes());
    expect($serialized)
        ->not->toContain(__FILE__)
        ->not->toContain('.php:')
        ->not->toContain('function (');
});

test('a named scheduled callback records the stable "callback:<name>" event name', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()->call(function () {})->name('rotate-cache-keys');

    EventFacade::dispatch(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    EventFacade::dispatch(new ScheduledTaskFinished($task, 0.0));

    $totals = schedulerEventTotalCalls($recorder);
    expect($totals[0]['attributes']['event_name'])->toBe('callback:rotate-cache-keys')
        ->and($totals[0]['attributes']['event_type'])->toBe('callback');
});

// 4. Scheduled queued job — Scheduler counts its own boundary once; Queue
// telemetry ownership (the job's own execution attempt) is untouched here
// since this scenario never actually dispatches the job to a real queue
// worker (Part 2's ownership boundary — this suite only proves Scheduler's
// side does not create a second Queue metric).
test('a scheduled queued job is counted once at the Scheduler boundary and never touches a Queue metric', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()->job(new SchedulerTelemetryTestJob);

    EventFacade::dispatch(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    EventFacade::dispatch(new ScheduledTaskFinished($task, 0.01));

    $totals = schedulerEventTotalCalls($recorder);
    expect($totals)->toHaveCount(1);
    expect($totals[0]['attributes']['event_type'])->toBe('callback');
    expect($totals[0]['attributes']['event_name'])->toBe('callback:'.SchedulerTelemetryTestJob::class);

    expect(array_filter($recorder->counterCalls, fn (array $call) => $call['name'] === 'ixora.queue.job.total'))->toBe([]);
});

// 5. Scheduled Artisan command — Console execution remains Console's own
// boundary; this module creates no ixora.console.* metric.
test('a scheduled Artisan command never creates a Console metric from the Scheduler module', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()->command('cache:clear');

    EventFacade::dispatch(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    EventFacade::dispatch(new ScheduledTaskFinished($task, 0.01));

    expect(array_filter($recorder->counterCalls, fn (array $call) => $call['name'] === 'ixora.console.command.total'))->toBe([]);
});

// 6. Manual Artisan command — dispatching a manual CommandStarting/
// CommandFinished pair (Console's own lifecycle, Phase 7B.2) never
// produces a Scheduler metric; the two modules are fully independent.
test('a manually invoked Artisan command never creates a Scheduler metric', function () {
    $recorder = fakeSchedulerTelemetry();

    EventFacade::dispatch(new CommandStarting(
        'migrate:status',
        new ArrayInput([]),
        new NullOutput,
    ));
    EventFacade::dispatch(new CommandFinished(
        'migrate:status',
        new ArrayInput([]),
        new NullOutput,
        0,
    ));

    expect(schedulerEventTotalCalls($recorder))->toBe([]);
});

// 7. schedule:run boundary — outer Console command + inner Scheduler
// event, recorded independently with distinct names, no cross-contamination.
test('the outer schedule:run command and an inner scheduled event are recorded independently with no cross-contamination', function () {
    $recorder = fakeSchedulerTelemetry();

    EventFacade::dispatch(new CommandStarting(
        'schedule:run',
        new ArrayInput([]),
        new NullOutput,
    ));

    $task = freshLaravelSchedule()->command('queue:prune-batches');
    EventFacade::dispatch(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    EventFacade::dispatch(new ScheduledTaskFinished($task, 0.01));

    EventFacade::dispatch(new CommandFinished(
        'schedule:run',
        new ArrayInput([]),
        new NullOutput,
        0,
    ));

    $schedulerTotals = schedulerEventTotalCalls($recorder);
    $consoleTotals = array_values(array_filter($recorder->counterCalls, fn (array $call) => $call['name'] === 'ixora.console.command.total'));

    expect($schedulerTotals)->toHaveCount(1)
        ->and($schedulerTotals[0]['attributes']['event_name'])->toBe('command:queue:prune-batches');

    expect($consoleTotals)->toHaveCount(1)
        ->and($consoleTotals[0]['attributes']['command'])->toBe('schedule:run');
});

// 8. schedule:work — repeated ticks record independent events, no stale
// context leaks between them.
test('repeated ticks (simulating schedule:work) record each event independently without leaking stale context', function () {
    $recorder = fakeSchedulerTelemetry();
    $telemetry = app(SchedulerExecutionTelemetry::class);

    for ($i = 0; $i < 3; $i++) {
        $task = freshLaravelSchedule()->command('queue:prune-batches');
        EventFacade::dispatch(new ScheduledTaskStarting($task));
        $task->exitCode = 0;
        EventFacade::dispatch(new ScheduledTaskFinished($task, 0.01));

        expect($telemetry->activeExecutionCount())->toBe(0);
    }

    expect(schedulerEventTotalCalls($recorder))->toHaveCount(3);
});

// 9. Skipped event — counted once, bounded outcome, no duration recorded,
// no dedicated span started.
test('a generic skipped event is counted once with a skipped outcome and no duration', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()->command('reports:send-weekly');

    EventFacade::dispatch(new ScheduledTaskSkipped($task));

    $totals = schedulerEventTotalCalls($recorder);
    expect($totals)->toHaveCount(1);
    expect($totals[0]['attributes']['outcome'])->toBe('skipped');

    expect(schedulerDurationCalls($recorder))->toBe([]);
    // No boundary span is created for a skip — no execution occurred.
    expect($recorder->startSpanCalls)->toBe([]);
});

// 10. withoutOverlapping() — the only reliable signal is the public
// Event::$skippedBecauseOverlapping flag read at ScheduledTaskFinished; no
// lock behavior is touched, no mutex key ever appears in telemetry.
test('withoutOverlapping() reports overlap_prevented via the reliable skippedBecauseOverlapping flag, never a mutex key', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()->command('reports:send-weekly')->name('send-weekly-reports')->withoutOverlapping();

    EventFacade::dispatch(new ScheduledTaskStarting($task));
    // Event::run() found the mutex already held — it returns having done
    // nothing, without ever calling finish() (so exitCode stays null), and
    // ScheduleRunCommand still dispatches ScheduledTaskFinished normally.
    $task->skippedBecauseOverlapping = true;
    EventFacade::dispatch(new ScheduledTaskFinished($task, 0.001));

    $totals = schedulerEventTotalCalls($recorder);
    expect($totals)->toHaveCount(1);
    expect($totals[0]['attributes']['outcome'])->toBe('overlap_prevented');

    $serialized = json_encode($totals[0]['attributes']).json_encode($recorder->mergedSpanAttributes());
    expect($serialized)->not->toContain($task->mutexName());
    expect($totals[0]['attributes'])->not->toHaveKey('mutex');
});

// 11. Background event.
test('a background scheduled command is recorded once as background_completed in the schedule:run process, with execution_mode=background', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()->command('reports:send-weekly')->runInBackground();

    EventFacade::dispatch(new ScheduledTaskStarting($task));
    // For a background event, Event::finish() (which sets the *real*
    // exit code) is never called in this process — only in the separate
    // `schedule:finish` process (see scenario below). exitCode stays null
    // here, which is exactly why background_completed (not success/failed)
    // is used.
    EventFacade::dispatch(new ScheduledTaskFinished($task, 0.01));

    $totals = schedulerEventTotalCalls($recorder);
    expect($totals)->toHaveCount(1);
    expect($totals[0]['attributes']['outcome'])->toBe('background_completed')
        ->and($totals[0]['attributes']['execution_mode'])->toBe('background');

    // No active-gauge metric exists at all in this phase (see class
    // docblock) — nothing to drift.
    expect(array_filter($recorder->upDownCounterCalls, fn (array $call) => $call['name'] === 'ixora.scheduler.event.active'))->toBe([]);
});

test('ScheduledBackgroundTaskFinished (a separate schedule:finish process) only enriches the active span, never a second metric', function () {
    $recorder = fakeSchedulerTelemetry();
    // A brand new SchedulerExecutionTelemetry instance simulates the fact
    // that `schedule:finish` is always a separate PHP process with empty
    // in-memory state (see class docblock) — it never sees the
    // ScheduledTaskStarting/Finished pair dispatched above in a real run.
    $telemetry = app(SchedulerExecutionTelemetry::class);

    $task = freshLaravelSchedule()->command('reports:send-weekly')->runInBackground();
    $task->exitCode = 0;

    $telemetry->scheduledBackgroundTaskFinished(new ScheduledBackgroundTaskFinished($task));

    expect(schedulerEventTotalCalls($recorder))->toBe([])
        ->and(schedulerDurationCalls($recorder))->toBe([]);

    $spanAttributes = $recorder->mergedSpanAttributes();
    expect($spanAttributes['ixora.scheduler.event_name'])->toBe('command:reports:send-weekly')
        ->and($spanAttributes['ixora.scheduler.execution_mode'])->toBe('background');

    expect($telemetry->activeExecutionCount())->toBe(0);
});

// 12. Unknown event metadata.
test('an event with no safely extractable metadata records bounded fallback values without throwing', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()->exec('');

    expect(fn () => EventFacade::dispatch(new ScheduledTaskStarting($task)))->not->toThrow(Throwable::class);

    $task->exitCode = 0;
    expect(fn () => EventFacade::dispatch(new ScheduledTaskFinished($task, 0.0)))->not->toThrow(Throwable::class);

    $totals = schedulerEventTotalCalls($recorder);
    expect($totals)->toHaveCount(1);
    // An empty exec() command has no artisan-shaped or safely-extractable
    // executable token — the bare, bounded `shell` type/name fallback
    // (Part 4), never SchedulerEventNormalizer::UNKNOWN (which is reserved
    // for a sanitize() failure, e.g. an all-control-character description).
    expect($totals[0]['attributes']['event_name'])->toBe('shell')
        ->and($totals[0]['attributes']['event_type'])->toBe('shell');
});

// 13. Cardinality safety.
test('no raw cron expression, full command line, arguments, or closure file path ever appears in scheduler metric labels', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()
        ->command('app:import-secrets', ['--token' => 'sk_live_super_secret', 'path' => '/etc/shadow'])
        ->cron('*/5 * * * *')
        ->timezone('America/Sao_Paulo');

    EventFacade::dispatch(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    EventFacade::dispatch(new ScheduledTaskFinished($task, 0.01));

    $totals = schedulerEventTotalCalls($recorder);
    $labelsSerialized = json_encode($totals[0]['attributes']);

    expect($labelsSerialized)
        ->not->toContain('sk_live_super_secret')
        ->not->toContain('/etc/shadow')
        ->not->toContain('--token')
        ->not->toContain('*/5 * * * *');

    expect($totals[0]['attributes'])->not->toHaveKeys([
        'expression', 'cron', 'timezone', 'argv', 'arguments', 'options',
        'schedule_id', 'vibe_id', 'user_id', 'device_id', 'mutex', 'process_id',
    ]);

    // The cron expression/timezone are allowed only as span attributes,
    // never as metric labels (Part 4).
    $spanAttributes = $recorder->mergedSpanAttributes();
    expect($spanAttributes['ixora.scheduler.expression'])->toBe('*/5 * * * *')
        ->and($spanAttributes['ixora.scheduler.timezone'])->toBe('America/Sao_Paulo');
});

// 14. Telemetry failure.
test('a broken Tracer never prevents a scheduled event lifecycle from completing', function () {
    $recorder = fakeSchedulerTelemetry();
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
    app()->forgetInstance(SchedulerExecutionTelemetry::class);

    $task = freshLaravelSchedule()->command('queue:prune-batches');

    expect(fn () => EventFacade::dispatch(new ScheduledTaskStarting($task)))->not->toThrow(Throwable::class);

    $task->exitCode = 0;
    expect(fn () => EventFacade::dispatch(new ScheduledTaskFinished($task, 0.01)))->not->toThrow(Throwable::class);

    // The counter/histogram add() calls happen before the broken
    // Tracer::activeSpan()/startSpan() calls — metrics are still recorded
    // even though span creation/enrichment failed.
    expect(schedulerEventTotalCalls($recorder))->toHaveCount(1);
});

test('a Counter::add()/Histogram::record() failure never prevents a scheduled event lifecycle from completing', function () {
    $task = freshLaravelSchedule()->command('queue:prune-batches');

    app()->bind(Tracer::class, fn () => new NoopTracer);
    app()->bind(Meter::class, fn () => new class implements Meter
    {
        public function counter(string $name, string $unit = '', string $description = ''): Counter
        {
            return new class implements Counter
            {
                public function add(int|float $amount, array $attributes = []): void
                {
                    throw new RuntimeException('counter exploded');
                }
            };
        }

        public function histogram(string $name, string $unit = '', string $description = ''): Histogram
        {
            return new class implements Histogram
            {
                public function record(int|float $value, array $attributes = []): void
                {
                    throw new RuntimeException('histogram exploded');
                }
            };
        }

        public function upDownCounter(string $name, string $unit = '', string $description = ''): UpDownCounter
        {
            return new class implements UpDownCounter
            {
                public function add(int|float $amount, array $attributes = []): void {}
            };
        }
    });
    app()->forgetInstance(SchedulerExecutionTelemetry::class);

    expect(fn () => EventFacade::dispatch(new ScheduledTaskStarting($task)))->not->toThrow(Throwable::class);

    $task->exitCode = 0;
    expect(fn () => EventFacade::dispatch(new ScheduledTaskFinished($task, 0.01)))->not->toThrow(Throwable::class);

    // The scheduled event's own exit code — what Illuminate\Console\
    // Scheduling\ScheduleRunCommand actually branches on — is completely
    // unaffected by either failure.
    expect($task->exitCode)->toBe(0);
});

// 15. Execution-state cleanup.
test('execution state returns to empty after success, failure, and skip, and a subsequent event is still recorded correctly', function () {
    $recorder = fakeSchedulerTelemetry();
    $telemetry = app(SchedulerExecutionTelemetry::class);

    $success = freshLaravelSchedule()->command('queue:prune-batches');
    EventFacade::dispatch(new ScheduledTaskStarting($success));
    $success->exitCode = 0;
    EventFacade::dispatch(new ScheduledTaskFinished($success, 0.01));
    expect($telemetry->activeExecutionCount())->toBe(0);

    $failure = freshLaravelSchedule()->call(function () {})->name('failing-callback');
    EventFacade::dispatch(new ScheduledTaskStarting($failure));
    $failure->exitCode = 1;
    EventFacade::dispatch(new ScheduledTaskFailed($failure, new RuntimeException('boom')));
    expect($telemetry->activeExecutionCount())->toBe(0);

    $skipped = freshLaravelSchedule()->command('reports:send-weekly');
    EventFacade::dispatch(new ScheduledTaskSkipped($skipped));
    expect($telemetry->activeExecutionCount())->toBe(0);

    $next = freshLaravelSchedule()->command('cache:clear');
    EventFacade::dispatch(new ScheduledTaskStarting($next));
    $next->exitCode = 0;
    EventFacade::dispatch(new ScheduledTaskFinished($next, 0.01));
    expect($telemetry->activeExecutionCount())->toBe(0);

    expect(schedulerEventTotalCalls($recorder))->toHaveCount(4);
});

// 16. Logging context — see SchedulerErrorContextLogTapTest.php for the
// full log-isolation suite (HTTP/Queue/Console/Scheduler separation).

// 17. No double instrumentation.
test('one scheduled event lifecycle produces exactly one counter record and at most one duration record', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()->command('cache:clear');

    EventFacade::dispatch(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    EventFacade::dispatch(new ScheduledTaskFinished($task, 0.01));

    expect(schedulerEventTotalCalls($recorder))->toHaveCount(1)
        ->and(schedulerDurationCalls($recorder))->toHaveCount(1);
});

test('a stray duplicate ScheduledTaskFinished for the same event is never recorded twice', function () {
    $recorder = fakeSchedulerTelemetry();
    $task = freshLaravelSchedule()->command('cache:clear');

    EventFacade::dispatch(new ScheduledTaskStarting($task));
    $task->exitCode = 0;
    EventFacade::dispatch(new ScheduledTaskFinished($task, 0.01));
    // A stray duplicate — no matching stack frame remains.
    EventFacade::dispatch(new ScheduledTaskFinished($task, 0.01));

    expect(schedulerEventTotalCalls($recorder))->toHaveCount(1);
});

// 18. Dependency rule — see tests/Unit/Telemetry/Scheduler/SchedulerTelemetryDependencyRuleTest.php.
