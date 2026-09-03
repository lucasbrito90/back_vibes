<?php

declare(strict_types=1);

namespace App\Telemetry\PushNotifications;

use App\Telemetry\Contracts\Span;
use App\Telemetry\Contracts\Tracer;
use Throwable;

/**
 * Business Telemetry for the Push Provider Execution boundary (Phase
 * 7B.6). Owns exactly one span, `push.provider`, wrapping the whole of
 * App\PushNotifications\Providers\FcmPushProvider::send() — unlike
 * HomeAssistantAdapter::executeAction() (Phase 7B.4.4), send() has no
 * unsupported-action guard clause to exclude first, so the entire method
 * (OAuth access-token acquisition through the FCM HTTP call and response
 * interpretation) is Provider-owned work with no natural sub-boundary.
 *
 * Mirrors App\Telemetry\SmartHome\SmartHomeProviderTelemetry's design and
 * the same architecture-review conclusions it documents
 * (backend-smart-home-provider-boundary.md), scoped to a second, unrelated
 * external provider (Firebase Cloud Messaging + Google's OAuth token
 * endpoint) per business-telemetry-foundation.md §7.3 ("Adding a new
 * provider to an existing domain" — per-adapter, no shared base class or
 * interface change):
 *
 * - open-telemetry/opentelemetry-auto-guzzle already creates a
 *   SpanKind::CLIENT span for every Illuminate\Support\Facades\Http call
 *   FcmPushProvider::send() makes (both the OAuth token exchange and the
 *   FCM send call), with url.full/http.request.method/
 *   http.response.status_code/exception already covered. This class
 *   therefore records NO url, method, status code, or duration attribute
 *   of its own.
 * - Unlike SmartHomeProviderTelemetry, there is no per-call varying
 *   business attribute equivalent to `ixora.provider.device_domain` at
 *   this boundary — FCM has no device/entity concept, and the
 *   notification's own business context (`notification_type`) already
 *   lives on `ixora.push.delivery.total`
 *   (App\Telemetry\PushNotifications\PushNotificationTelemetry, Phase
 *   7B.5), recorded one layer up in PushNotificationJob. This span
 *   therefore carries no custom attribute at all — same reasoning
 *   SmartHomeProviderTelemetry gives for never recording
 *   `ixora.provider.name` (only one adapter exists per domain today; a
 *   future second Push provider gets its own call site producing the same
 *   span name, not a label on this one).
 * - No outcome attribute is recorded here either: a returned, non-throwing
 *   PushResult::failure() (e.g. FCM responding with a non-2xx status) is
 *   already fully visible one level deeper, on the nested Guzzle CLIENT
 *   span's own http.response.status_code — this class marks the span as
 *   an error via recordException()/setError() only for an unexpected
 *   Throwable escaping send() entirely (e.g. FcmAuthenticationException,
 *   FcmConfigurationException).
 *
 * Consumes only App\Telemetry\Contracts\{Tracer,Span} — no OpenTelemetry
 * SDK import, no App\Models\*, App\PushNotifications\*, App\Jobs\*, or
 * HTTP client import anywhere in this class (enforced by the platform-wide
 * scan in tests/Unit/Telemetry/DependencyRuleTest.php, the same generic
 * enforcement PushNotificationTelemetry, Phase 7B.5, already relies on —
 * no dedicated per-class dependency-rule test file for this module).
 * No metric is created here — the existing `ixora.push.delivery.total`
 * (Phase 7B.5) already covers delivery outcome.
 */
final class PushProviderTelemetry
{
    private const SPAN_NAME = 'push.provider';

    public function __construct(private readonly Tracer $tracer) {}

    /**
     * Wraps one FcmPushProvider::send() call in full — OAuth access-token
     * acquisition, the FCM HTTP call, and response interpretation.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $execute
     * @return TResult
     */
    public function wrap(callable $execute): mixed
    {
        $span = $this->startSpan();

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
     * whatever span is already active — expected to be the auto-
     * instrumented Queue Consumer span for `PushNotificationJob.handle`
     * (Phase 7B.2). Never calls Tracer::activeSpan() and never replaces
     * the active span — additive, not a takeover, exactly like
     * SmartHomeProviderTelemetry.
     */
    private function startSpan(): Span
    {
        try {
            return $this->tracer->startSpan(self::SPAN_NAME);
        } catch (Throwable) {
            return $this->inertSpan();
        }
    }

    /**
     * A local, dependency-rule-safe stand-in for a span — mirrors
     * SmartHomeProviderTelemetry::inertSpan() exactly, for the same
     * reason: App\Telemetry\Noop\NoopSpan is deliberately not reused here
     * so this module never imports a concrete OpenTelemetry or Noop
     * implementation, only the Contracts.
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
            // PushResult construction, exceptions, retry behavior, queue
            // behavior, or HTTP behavior (telemetry-availability-policy.md).
        }
    }
}
