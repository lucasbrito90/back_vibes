<?php

declare(strict_types=1);

namespace App\Telemetry\SmartHome;

use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use Throwable;

/**
 * Business Telemetry for the Smart Home Provider Execution boundary
 * (Phase 7B.4.4). Owns exactly one span, `smart_home.provider`, wrapping
 * the provider-specific segment of
 * App\SmartHome\Adapters\HomeAssistantAdapter::executeAction() — see
 * backend-smart-home-provider-boundary.md §"Boundary discovery" for the
 * full architecture review this class's design depends on. Summary of that
 * review's conclusions:
 *
 * - The natural boundary is NOT the entire executeAction() method.
 *   executeAction() begins with an unsupported-action lookup against a
 *   Home-Assistant-specific service map that happens before any provider
 *   I/O, connection use, or domain/payload construction — throwing
 *   UnsupportedSmartHomeActionException synchronously for an unmappable
 *   action, exactly mirroring Phase 7B.4.3's own reasoning for why its
 *   three leading guard clauses stay outside `smart_home.action`. This
 *   class is therefore never invoked at all for an unsupported action —
 *   HomeAssistantAdapter::executeAction() calls wrap() only once the
 *   action is confirmed mappable to a real HA service call.
 * - The boundary begins immediately after that check (at HA-specific
 *   domain derivation) and ends at ActionResult construction — wrapping
 *   domain derivation, payload construction, the authenticated HTTP call,
 *   and response interpretation. It does NOT wrap credential decryption in
 *   isolation (that happens inside the same segment, but is never logged
 *   or exported — see Security review) and never extends past
 *   executeAction()'s own return/throw.
 * - open-telemetry/opentelemetry-auto-guzzle already creates a
 *   SpanKind::CLIENT span (named after the HTTP method, e.g. "POST") for
 *   every Illuminate\Support\Facades\Http call this segment makes, with
 *   url.full/http.request.method/http.response.status_code/exception
 *   recording already covered. This class therefore records NO url,
 *   method, status code, or duration attribute of its own — doing so would
 *   duplicate that existing, unmodified auto-instrumentation. Because
 *   Tracer::startSpan() activates the new span as the current context
 *   before this class's $execute closure runs, the Guzzle span nests under
 *   `smart_home.provider` for free, via the same propagation mechanism
 *   `smart_home.provider` itself relies on to nest under `smart_home.action`.
 * - `ixora.action.provider` (Phase 7B.4.3) is NOT duplicated here: it must
 *   remain readable even when no Provider span is ever created at all
 *   (unsupported action, or ProviderAdapterResolver rejecting an unknown
 *   provider slug before any adapter — and therefore any Provider span —
 *   can exist), so it stays exclusively on `smart_home.action`.
 * - No outcome attribute is recorded here either: a returned, non-throwing
 *   ActionResult(success: false) — e.g. the provider responding with a
 *   non-2xx status — is already fully visible one level deeper, on the
 *   nested Guzzle CLIENT span's own http.response.status_code /
 *   error-status, or (for a caught ConnectionException) on that same
 *   span's own recorded exception. Duplicating a redundant
 *   success/failure attribute up here — identical, in the current
 *   single-adapter implementation, to `smart_home.action`'s own outcome —
 *   was explicitly rejected as duplication (unlike
 *   App\Telemetry\Queue\QueueOutcome, which classifies a genuinely
 *   different concept than the Action's own outcome). This class marks
 *   the span as an error via recordException()/setError() only for an
 *   unexpected Throwable escaping the wrapped segment entirely (e.g. a
 *   credential-decryption failure) — never for a normally-returned,
 *   business-legitimate failed ActionResult.
 *
 * Consumes only App\Telemetry\Contracts\{Tracer,Span} — no OpenTelemetry
 * SDK import, no App\Models\*, App\SmartHome\*, App\Jobs\*, or HTTP client
 * import anywhere in this class (enforced by
 * tests/Unit/Telemetry/SmartHome/SmartHomeProviderTelemetryDependencyRuleTest.php).
 * No metric is created here — business metrics begin in Phase 7B.4.6. No
 * logging is changed here — business logging begins in Phase 7B.4.7.
 */
final class SmartHomeProviderTelemetry
{
    private const SPAN_NAME = 'smart_home.provider';

    public function __construct(private readonly Tracer $tracer) {}

    /**
     * Wraps one HomeAssistantAdapter provider call: the provider-specific
     * segment of executeAction() (domain/payload construction through the
     * HTTP call and response interpretation), or — as of Phase 7B.6 — the
     * whole of listDevices(), readStatus(), or testConnection(), none of
     * which have an unsupported-action guard clause to exclude first (see
     * backend-smart-home-provider-boundary.md §9, which deferred exactly
     * these three methods to "later phases").
     *
     * $deviceDomain is the raw HA-style entity domain string the caller
     * already derived (e.g. "light") for a single-device call —
     * readStatus() passes one, mirroring executeAction(). listDevices()
     * and testConnection() have no single device (a full-catalog fetch and
     * a pure connectivity check, respectively), so they pass null and the
     * span carries no device_domain attribute at all — never a specific
     * entity_id or provider_device_id either way. $execute performs the
     * real call and returns its result (or throws), unchanged.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $execute
     * @return TResult
     */
    public function wrap(?string $deviceDomain, callable $execute): mixed
    {
        $span = $this->startSpan($deviceDomain);

        try {
            $result = $execute();
        } catch (Throwable $exception) {
            $this->safely(function () use ($span, $exception) {
                $span->recordException($exception);
                $span->setError();
            });
            $this->safely(fn () => $span->end());

            throw $exception;
        }

        $this->safely(fn () => $span->end());

        return $result;
    }

    /**
     * Starts the one Business Span this class ever creates, as a child of
     * whatever span is already active — expected to be `smart_home.action`
     * (Phase 7B.4.3), itself already nested under the Queue Consumer span
     * and `smart_home.dispatch` (Phase 7B.4.2). Never calls
     * Tracer::activeSpan() and never replaces the active span — additive,
     * not a takeover, exactly like SmartHomeActionTelemetry.
     */
    private function startSpan(?string $deviceDomain): Span
    {
        try {
            $attributes = $deviceDomain === null
                ? []
                : ['ixora.provider.device_domain' => SmartHomeProviderDeviceDomain::fromDomainSlug($deviceDomain)->value];

            return $this->tracer->startSpan(self::SPAN_NAME, $attributes);
        } catch (Throwable) {
            return $this->inertSpan();
        }
    }

    /**
     * A local, dependency-rule-safe stand-in for a span — mirrors
     * App\Telemetry\SmartHome\SmartHomeActionTelemetry::inertSpan() and
     * App\Telemetry\SmartHome\SmartHomeDispatchTelemetry::inertSpan()
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
            // ActionResult construction, exceptions, retry behavior, queue
            // behavior, or HTTP behavior (telemetry-availability-policy.md).
        }
    }
}
