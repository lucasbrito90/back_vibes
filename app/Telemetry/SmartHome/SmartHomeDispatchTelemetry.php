<?php

declare(strict_types=1);

namespace App\Telemetry\SmartHome;

use App\Telemetry\Contracts\Counter;
use App\Telemetry\Contracts\Meter;
use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use Throwable;

/**
 * Business Telemetry for the Smart Home dispatch boundary (Phase 7B.4.2 —
 * the first Business Telemetry boundary, built on the Phase 7B.4.1 Domain
 * Execution Review). Owns exactly one span, `smart_home.dispatch`, wrapping
 * App\SmartHome\Services\VibeSmartHomeDispatchService::dispatch() from
 * immediately before the call to immediately after it returns or throws —
 * see backend-smart-home-dispatch-boundary.md §"Boundary".
 *
 * Deliberately instruments at the two call sites
 * (VibeSmartHomeDispatchController, DispatchDueSchedulesCommand) instead of
 * inside VibeSmartHomeDispatchService itself: the Domain Execution Review
 * (Phase 7B.4.1) found no Laravel event fired around this call site (unlike
 * Queue/Console/Scheduler — see backend-generic-scheduler-instrumentation.md
 * §"Duplication risks"), so there is no lifecycle event to listen to.
 * Wrapping the call is the only way to add a span without editing
 * VibeSmartHomeDispatchService's own source — honoring "observe the domain,
 * never redesign it" and keeping the dispatch service completely unaware
 * that telemetry, a controller, or a scheduler exist.
 *
 * Pre-implementation review findings this class's design depends on (full
 * detail in backend-smart-home-dispatch-boundary.md §"Pre-implementation
 * review"):
 *
 * - opentelemetry-auto-laravel already injects a W3C traceparent into every
 *   queued job's payload at dispatch() time
 *   (Illuminate\Queue\Queue::createPayloadArray()'s post-hook — see
 *   backend-queue-console-instrumentation.md §2) and already extracts it
 *   back as the consumer span's parent context in Worker::process().
 *   Tracer::startSpan() activates the span it creates
 *   (App\Telemetry\OpenTelemetry\OpenTelemetryTracer::startSpan() calls
 *   SpanInterface::activate()) — so every SmartHomeActionJob::dispatch()
 *   call made *while* the `smart_home.dispatch` span is the active span
 *   automatically has that trace context injected as its parent, with zero
 *   custom propagation code. This is why this class implements no
 *   correlation ID, no custom context object, and makes no change to
 *   SmartHomeActionJob — duplicating that propagation was the one thing
 *   Phase 7B.4.2 was explicitly forbidden from doing.
 * - The queue driver in production is `database` (async) — dispatch() only
 *   enqueues a row; the span (ended immediately after dispatch() returns)
 *   never overlaps real job/provider execution. Under the `sync` driver
 *   (this repository's own test-suite override, phpunit.xml), a queued job
 *   runs inline inside push() — an already-documented, accepted asymmetry
 *   (backend-queue-console-instrumentation.md §2 "Sync queue behavior"),
 *   not something this class special-cases; this phase's own tests use
 *   Bus::fake() to keep span-lifetime assertions independent of queue
 *   driver behavior.
 *
 * Fail-open throughout (telemetry-availability-policy.md): wrap() is safe to
 * call even when the Tracer is broken or telemetry is disabled —
 * startSpan() falls back to an inert Span, and every other telemetry
 * operation swallows Throwable. Business behavior (loading actions,
 * ordering, enqueueing SmartHomeActionJob, counting, returning
 * SmartHomeDispatchResult, and any exception dispatch() itself raises) is
 * never affected — the exception, if any, is always rethrown unchanged.
 *
 * Consumes only App\Telemetry\Contracts\{Tracer,Span,Meter,Counter} — no
 * OpenTelemetry SDK import, no App\Models\*, App\SmartHome\*, App\Http\*, or
 * App\Console\Commands\* import anywhere in this class (enforced by
 * tests/Unit/Telemetry/SmartHome/SmartHomeDispatchTelemetryDependencyRuleTest.php).
 * No logging is changed here — business logging begins in Phase 7B.4.7.
 *
 * Business Metrics (Phase 7B.4.6 — backend-smart-home-business-metrics.md):
 * records exactly one Counter, `ixora.smart_home.dispatch.total` (unit
 * `{action}`), labeled `entry_point` (manual/scheduled/future) and
 * `outcome`. Unlike the Action boundary, one `dispatch()` call does not
 * produce a single pass/fail outcome — it produces a *batch* of two counts
 * (dispatched, skipped) plus, rarely, a thrown exception — so this class
 * records the counter up to three times per wrap() call, once per outcome
 * value that actually occurred:
 *
 * - `outcome=dispatched`, incremented by the batch's own dispatched-action
 *   count (D1, always recorded, even when the count is zero).
 * - `outcome=skipped`, incremented by the batch's own skipped-action count
 *   (D1, always recorded, even when the count is zero) — never folded into
 *   `dispatched` or `error` (Phase 7B.4.5 §3.1 classifies a skip as its own
 *   distinct, expected Business outcome, not a failure).
 * - `outcome=error`, incremented by exactly 1 (never by an action count —
 *   the batch itself did not complete, so no per-action counts exist) only
 *   when `dispatch()` throws (D2) — mirrors the Scheduler's own precedent
 *   (`ixora.scheduler.execution.total{outcome=failed}`,
 *   backend-business-failure-semantics.md §11).
 *
 * Recorded inside this class's own safely() fail-open guard — a broken
 * Meter/Counter degrades to "no metric recorded", never to an affected
 * dispatch() result or exception. Labels never include an ID (see
 * backend-smart-home-business-metrics.md §"Cardinality review").
 */
final class SmartHomeDispatchTelemetry
{
    private const SPAN_NAME = 'smart_home.dispatch';

    private const METRIC_DISPATCH_TOTAL = 'ixora.smart_home.dispatch.total';

    private readonly Counter $dispatchTotal;

    public function __construct(
        private readonly Tracer $tracer,
        Meter $meter,
        private readonly string $environment,
        private readonly string $serviceName,
    ) {
        $this->dispatchTotal = $meter->counter(
            self::METRIC_DISPATCH_TOTAL,
            unit: '{action}',
            description: 'Total Smart Home actions dispatched, skipped, or lost to a dispatch failure, labeled by entry point and outcome.',
        );
    }

    /**
     * Wraps a single VibeSmartHomeDispatchService::dispatch() call.
     *
     * $dispatch performs the real call and returns its result. $extractCounts
     * reads [dispatched, skipped] off that result — kept as a
     * caller-supplied closure, not a App\SmartHome\DTOs\SmartHomeDispatchResult
     * type-hint, so this class never imports Smart Home domain code.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $dispatch
     * @param  callable(TResult): array{0: int, 1: int}  $extractCounts
     * @return TResult
     */
    public function wrap(SmartHomeDispatchEntryPoint $entryPoint, callable $dispatch, callable $extractCounts): mixed
    {
        $span = $this->startSpan($entryPoint);

        try {
            $result = $dispatch();
        } catch (Throwable $exception) {
            $this->safely(function () use ($span, $exception) {
                $span->recordException($exception);
                $span->setError();
            });
            $this->safely(fn () => $this->recordCounter($entryPoint, 'error', 1));
            $this->safely(fn () => $span->end());

            throw $exception;
        }

        $this->safely(function () use ($span, $entryPoint, $extractCounts, $result) {
            [$dispatchedActions, $skippedActions] = $extractCounts($result);

            $span->setAttributes([
                'ixora.dispatch.dispatched_actions' => $dispatchedActions,
                'ixora.dispatch.skipped_actions' => $skippedActions,
            ]);

            $this->recordCounter($entryPoint, 'dispatched', $dispatchedActions);
            $this->recordCounter($entryPoint, 'skipped', $skippedActions);
        });
        $this->safely(fn () => $span->end());

        return $result;
    }

    /**
     * Records one `ixora.smart_home.dispatch.total` increment — see class
     * docblock, "Business Metrics", for why up to three of these happen
     * per wrap() call instead of exactly one.
     */
    private function recordCounter(SmartHomeDispatchEntryPoint $entryPoint, string $outcome, int $amount): void
    {
        $this->dispatchTotal->add($amount, [
            'environment' => $this->environment,
            'service_name' => $this->serviceName,
            'entry_point' => $entryPoint->value,
            'outcome' => $outcome,
        ]);
    }

    /**
     * Starts the one Business Span this class ever creates, as a child of
     * whatever infrastructure span (HTTP or Console — see class docblock) is
     * already active, per Tracer::startSpan()'s own contract. Never calls
     * Tracer::activeSpan() and never replaces the active span — this is
     * additive, not a takeover (Phase 7B.4.2 "Active span" requirement).
     */
    private function startSpan(SmartHomeDispatchEntryPoint $entryPoint): Span
    {
        try {
            return $this->tracer->startSpan(self::SPAN_NAME, [
                'ixora.dispatch.entry_point' => $entryPoint->value,
            ]);
        } catch (Throwable) {
            return $this->inertSpan();
        }
    }

    /**
     * A local, dependency-rule-safe stand-in for a span — mirrors
     * App\Telemetry\Scheduler\SchedulerExecutionTelemetry::inertSpan()
     * exactly, for the same reason: App\Telemetry\Noop\NoopSpan is
     * deliberately not reused here so this module never imports a concrete
     * OpenTelemetry or Noop implementation, only the Contracts.
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

    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable) {
            // Intentionally swallowed — telemetry must never affect
            // dispatch(), queue dispatch, or the returned
            // SmartHomeDispatchResult (telemetry-availability-policy.md).
        }
    }
}
