<?php

declare(strict_types=1);

namespace App\Telemetry\Contracts;

/**
 * Single entry point for the Telemetry Abstraction Layer. Domain code
 * resolves this contract (or the more specific Tracer / Meter /
 * LoggerCorrelation contracts) from the container — never the OpenTelemetry
 * SDK classes in App\Telemetry\OpenTelemetry (Dependency Rule, Phase 7A).
 *
 * Future implementations (no-op, testing, benchmark, alternative vendors)
 * bind to this same contract with zero changes required in calling code —
 * see backend-sdk-foundation.md §"Future compatibility".
 */
interface TelemetryManager
{
    public function tracer(): Tracer;

    public function meter(): Meter;

    public function loggerCorrelation(): LoggerCorrelation;

    /**
     * Whether telemetry export is active for this process. False when
     * disabled via configuration or when SDK bootstrap failed — in both
     * cases the bound implementation still behaves as a safe no-op
     * (telemetry-availability-policy.md).
     */
    public function isEnabled(): bool;

    /**
     * Best-effort flush of any buffered telemetry. MUST NOT throw and MUST
     * complete within a bounded internal timeout — never blocks the caller
     * indefinitely (telemetry-availability-policy.md R3).
     *
     * @return bool Whether the flush completed before its internal timeout.
     */
    public function flush(): bool;
}
