<?php

declare(strict_types=1);

namespace App\Telemetry\SmartHome;

use Throwable;

/**
 * Outcome metadata from one SmartHomeActionTelemetry::wrapWithMetadata() call.
 *
 * When {@see $thrownException} is non-null the wrapped segment failed; the job
 * uses the pre-classified {@see $outcome} and trace/duration captured before
 * the span was ended — without importing domain types into telemetry.
 *
 * @template TResult
 */
final readonly class SmartHomeActionWrapResult
{
    /**
     * @param  TResult|null  $result
     */
    public function __construct(
        public mixed $result,
        public SmartHomeActionOutcome $outcome,
        public ?string $traceId,
        public ?int $durationMs,
        public ?Throwable $thrownException,
    ) {}
}
