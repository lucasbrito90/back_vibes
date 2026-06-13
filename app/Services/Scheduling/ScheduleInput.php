<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use Carbon\CarbonImmutable;

/**
 * Immutable value object carrying all inputs RecurrenceService needs.
 *
 * Database-agnostic — no Eloquent, no DB dependency.
 * start_time is stored and passed as a UTC CarbonImmutable instant.
 * Recurrence expansion is performed in the schedule's IANA timezone.
 */
final class ScheduleInput
{
    public function __construct(
        /** IANA timezone string, e.g. "America/Sao_Paulo" */
        public readonly string $timezone,

        /** First anchor instant, stored and provided as UTC. */
        public readonly CarbonImmutable $startTime,

        public readonly RecurrenceType $recurrenceType,

        /**
         * Optional recurrence configuration.
         *
         * Required shape for weekly:
         *   ['days_of_week' => [1..7]]  (ISO 8601: 1=Monday … 7=Sunday)
         *
         * null for once, daily, weekdays.
         *
         * @var array<string, mixed>|null
         */
        public readonly ?array $recurrenceConfig,

        public readonly bool $isEnabled,
    ) {}
}
