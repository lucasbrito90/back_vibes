<?php

declare(strict_types=1);

namespace App\Telemetry\SmartHome;

use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use Throwable;

/**
 * Business Telemetry for the Smart Home Action Execution boundary
 * (Phase 7B.4.3). Owns exactly one span, `smart_home.action`, wrapping the
 * business-execution portion of App\Jobs\SmartHome\SmartHomeActionJob::
 * handle() — see backend-smart-home-action-execution.md §"Boundary
 * discovery" for the full architecture review this class's design depends
 * on. Summary of that review's conclusions:
 *
 * - The natural boundary is NOT the entire handle() method. handle() begins
 *   with three sequential guard clauses (action not found, device missing,
 *   provider connection missing) that return early after only logging a
 *   warning — no provider adapter is ever resolved or invoked
 *   for any of them, and none of them corresponds to a value in this
 *   phase's allowed `ixora.action.outcome` set (success/failure/
 *   unsupported). This class is therefore never invoked at all for those
 *   three paths — SmartHomeActionJob::handle() calls wrap() only once it
 *   already holds a resolved, non-null action + device + provider
 *   connection, exactly mirroring the precedent Phase 7B.4.2 set (no
 *   `smart_home.dispatch` span for a request that fails authorization or
 *   validation before the dispatch boundary is ever reached).
 * - The boundary begins immediately before `ProviderAdapterResolver::
 *   forProvider()` (provider resolution — pure in-memory dispatch, no I/O)
 *   and ends immediately after `ProviderAdapter::executeAction()` returns
 *   or throws (business orchestration + action outcome) — never wrapping
 *   `$this->logResult()` (logging is out of scope this phase, deferred to
 *   Phase 7B.4.7) or `$this->notifyActionFailed()` (a decoupled,
 *   fire-and-forget push side effect the Domain Execution Review already
 *   found never affects the action's own outcome).
 * - `HomeAssistantAdapter::executeAction()` throws
 *   `UnsupportedSmartHomeActionException` synchronously, before any HTTP
 *   call, for an unmappable action type — this exception is a legitimate
 *   business outcome (`unsupported`), not a provider-communication failure,
 *   which is exactly why this phase's outcome set has a distinct value for
 *   it. For every other Throwable (e.g. ProviderAdapterResolver rejecting
 *   an unknown provider slug, or any other unexpected error), the outcome
 *   is `failure`.
 * - This class never imports App\SmartHome\Exceptions\
 *   UnsupportedSmartHomeActionException (or any other domain type) to tell
 *   these two cases apart — the caller supplies $classifyException, a
 *   closure that already has that domain knowledge, exactly mirroring how
 *   Phase 7B.4.2's SmartHomeDispatchTelemetry::wrap() takes an
 *   $extractCounts closure instead of importing SmartHomeDispatchResult.
 * - Exactly one ProviderAdapter::executeAction() call happens per wrap()
 *   call — there is no loop and no internal retry inside the wrapped
 *   segment, confirmed by reading HomeAssistantAdapter::executeAction() and
 *   SmartHomeActionJob::handle() in full. "Provider execution" and
 *   "business action execution" are therefore 1:1 for every attempt today.
 * - SmartHomeActionJob::handle() never lets any exception escape it — both
 *   its own UnsupportedSmartHomeActionException catch block and its
 *   generic Throwable catch-all log and swallow, unconditionally. This
 *   class's own Failure Model (recordException()/setError()/end()/rethrow
 *   unchanged) does not change this: the exception this class rethrows is
 *   caught by handle()'s own, pre-existing, unmodified catch blocks exactly
 *   as it always was — Laravel's queue retry mechanism (`tries = 3`) is
 *   therefore never actually triggered by this job in practice, a
 *   pre-existing architectural fact this phase does not change and must
 *   not be mistaken for something this class controls.
 *
 * Consumes only App\Telemetry\Contracts\{Tracer,Span} — no OpenTelemetry SDK
 * import, no App\Models\*, App\SmartHome\*, App\Jobs\*, or queue/HTTP/
 * scheduler import anywhere in this class (enforced by
 * tests/Unit/Telemetry/SmartHome/SmartHomeActionTelemetryDependencyRuleTest.php).
 * No metric is created here — business metrics begin in Phase 7B.4.6. No
 * logging is changed here — business logging begins in Phase 7B.4.7. No
 * provider-communication instrumentation is added here — that is Phase
 * 7B.4.4's Provider Boundary, which will nest under this class's span for
 * free via the same startSpan()-activates-context propagation mechanism
 * this class itself relies on to nest under the Queue Consumer span.
 */
final class SmartHomeActionTelemetry
{
    private const SPAN_NAME = 'smart_home.action';

    public function __construct(private readonly Tracer $tracer) {}

    /**
     * Wraps the provider-resolution + provider-execution segment of one
     * SmartHomeActionJob::handle() invocation.
     *
     * $execute performs the real call (provider resolution + executeAction())
     * and returns its result. $classifyResult maps a successful result to an
     * outcome; $classifyException maps a caught Throwable to an outcome —
     * kept as caller-supplied closures, not domain type-hints, so this class
     * never imports Smart Home domain code.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $execute
     * @param  callable(TResult): SmartHomeActionOutcome  $classifyResult
     * @param  callable(Throwable): SmartHomeActionOutcome  $classifyException
     * @return TResult
     */
    public function wrap(
        SmartHomeActionProvider $provider,
        bool $isRetryAttempt,
        callable $execute,
        callable $classifyResult,
        callable $classifyException,
    ): mixed {
        $span = $this->startSpan($provider, $isRetryAttempt);

        try {
            $result = $execute();
        } catch (Throwable $exception) {
            $outcome = $this->classify($classifyException, $exception);

            $this->safely(function () use ($span, $outcome, $exception) {
                $span->setAttribute('ixora.action.outcome', $outcome->value);
                $span->recordException($exception);
                $span->setError();
            });
            $this->safely(fn () => $span->end());

            throw $exception;
        }

        $outcome = $this->classify($classifyResult, $result);

        $this->safely(fn () => $span->setAttribute('ixora.action.outcome', $outcome->value));
        $this->safely(fn () => $span->end());

        return $result;
    }

    /**
     * Starts the one Business Span this class ever creates, as a child of
     * whatever span is already active — expected to be the Queue Consumer
     * span (async) or the sync queue's internal "process" span (sync), per
     * Phase 7B.2's own findings, itself already nested under
     * `smart_home.dispatch` via existing trace-context propagation (Phase
     * 7B.4.2 §"Pre-implementation review"). Never calls
     * Tracer::activeSpan() and never replaces the active span — additive,
     * not a takeover, exactly like SmartHomeDispatchTelemetry.
     */
    private function startSpan(SmartHomeActionProvider $provider, bool $isRetryAttempt): Span
    {
        try {
            return $this->tracer->startSpan(self::SPAN_NAME, [
                'ixora.action.provider' => $provider->value,
                'ixora.action.retry' => $isRetryAttempt,
            ]);
        } catch (Throwable) {
            return $this->inertSpan();
        }
    }

    /**
     * Runs a caller-supplied classifier closure with its own fail-open
     * guard — a broken classifier must never affect dispatch()/handle()'s
     * business result, only degrade this class's own span attribute to the
     * reserved Unknown outcome.
     *
     * @template TInput
     *
     * @param  callable(TInput): SmartHomeActionOutcome  $classifier
     * @param  TInput  $input
     */
    private function classify(callable $classifier, mixed $input): SmartHomeActionOutcome
    {
        try {
            return $classifier($input);
        } catch (Throwable) {
            return SmartHomeActionOutcome::Unknown;
        }
    }

    /**
     * A local, dependency-rule-safe stand-in for a span — mirrors
     * App\Telemetry\SmartHome\SmartHomeDispatchTelemetry::inertSpan() and
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
            // Intentionally swallowed — telemetry must never affect queue
            // processing, retries, business execution, or provider
            // execution (telemetry-availability-policy.md).
        }
    }
}
