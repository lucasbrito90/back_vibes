<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Services\Scheduling\Exceptions\InvalidRecurrenceConfigurationException;
use App\Services\Scheduling\Exceptions\UnsupportedRecurrenceTypeException;
use Carbon\CarbonImmutable;

/**
 * Pure, deterministic, database-agnostic recurrence calculator.
 *
 * All computations follow ADR-009 (UTC storage, timezone-aware expansion, DST policy)
 * and ADR-010 (idempotent occurrence_key format).
 *
 * DST policies (ADR-009):
 *  - Spring-forward (nonexistent local time): skip the occurrence, advance to the next valid date.
 *  - Fall-back (ambiguous local time): use the first/earlier UTC instant (pre-transition).
 *
 * monthly recurrence is reserved for a future phase and always throws
 * UnsupportedRecurrenceTypeException.
 */
final class RecurrenceService
{
    /**
     * Maximum number of calendar days to scan before giving up.
     * 400 days comfortably covers a year plus rare DST edge cases.
     */
    private const MAX_SCAN_DAYS = 400;

    /**
     * Compute the next UTC instant at which a schedule should fire.
     *
     * @param  ScheduleInput  $input  Schedule configuration (database-agnostic DTO).
     * @param  CarbonImmutable|null  $afterUtc  The returned instant must be strictly > this value.
     *                                          Defaults to now(UTC) when null.
     * @return CarbonImmutable|null Next occurrence in UTC, or null when no future occurrence exists.
     *
     * @throws UnsupportedRecurrenceTypeException When recurrence_type is monthly (MVP reserved).
     * @throws InvalidRecurrenceConfigurationException When weekly is used with an invalid days_of_week.
     */
    public function computeNextRunAt(ScheduleInput $input, ?CarbonImmutable $afterUtc = null): ?CarbonImmutable
    {
        if (! $input->isEnabled) {
            return null;
        }

        $afterUtc ??= CarbonImmutable::now('UTC');

        return match ($input->recurrenceType) {
            RecurrenceType::Once => $this->computeOnce($input, $afterUtc),
            RecurrenceType::Daily => $this->computeRecurring($input, $afterUtc, fn (int $dow) => true),
            RecurrenceType::Weekdays => $this->computeRecurring($input, $afterUtc, fn (int $dow) => $dow >= 1 && $dow <= 5),
            RecurrenceType::Weekly => $this->computeWeekly($input, $afterUtc),
            RecurrenceType::Monthly => throw new UnsupportedRecurrenceTypeException($input->recurrenceType),
        };
    }

    /**
     * Build a stable idempotency key for a (schedule_id, scheduled_for UTC) pair.
     *
     * Format: "{schedule_id}:{scheduled_for_unix}"  (ADR-010)
     * The key contains only integers separated by a colon — no timezone display strings.
     */
    public function computeOccurrenceKey(int $scheduleId, CarbonImmutable $scheduledForUtc): string
    {
        return "{$scheduleId}:{$scheduledForUtc->utc()->timestamp}";
    }

    // -----------------------------------------------------------------------
    // Private — recurrence strategies
    // -----------------------------------------------------------------------

    private function computeOnce(ScheduleInput $input, CarbonImmutable $afterUtc): ?CarbonImmutable
    {
        $anchor = $input->startTime->utc();

        return $anchor->gt($afterUtc) ? $anchor : null;
    }

    private function computeWeekly(ScheduleInput $input, CarbonImmutable $afterUtc): ?CarbonImmutable
    {
        $days = $this->validatedWeeklyDays($input);

        return $this->computeRecurring(
            $input,
            $afterUtc,
            fn (int $dow) => in_array($dow, $days, strict: true)
        );
    }

    /**
     * Extract and validate days_of_week from recurrence_config.
     *
     * @return int[] Validated ISO weekday list (1–7).
     *
     * @throws InvalidRecurrenceConfigurationException
     */
    private function validatedWeeklyDays(ScheduleInput $input): array
    {
        $days = $input->recurrenceConfig['days_of_week'] ?? null;

        if (! is_array($days) || count($days) === 0) {
            throw InvalidRecurrenceConfigurationException::weeklyMissingDaysOfWeek();
        }

        foreach ($days as $day) {
            if (! is_int($day) || $day < 1 || $day > 7) {
                throw InvalidRecurrenceConfigurationException::weeklyInvalidDayValue($day);
            }
        }

        return $days;
    }

    /**
     * Scan forward day-by-day, testing $dayFilter(ISO-weekday) and DST rules,
     * until a strictly-after-$afterUtc UTC instant is found.
     *
     * @param  callable(int): bool  $dayFilter  Receives ISO weekday (1=Mon … 7=Sun).
     */
    private function computeRecurring(
        ScheduleInput $input,
        CarbonImmutable $afterUtc,
        callable $dayFilter,
    ): ?CarbonImmutable {
        $tz = $input->timezone;

        // Local wall time extracted from the UTC anchor.
        $anchorLocal = $input->startTime->setTimezone($tz);
        $anchorHour = $anchorLocal->hour;
        $anchorMin = $anchorLocal->minute;
        $anchorSec = $anchorLocal->second;

        // Start scanning from the local date of $afterUtc in the schedule timezone.
        $cursor = $afterUtc->setTimezone($tz)->startOfDay();

        for ($i = 0; $i < self::MAX_SCAN_DAYS; $i++) {
            $localDay = $cursor->addDays($i);
            $y = $localDay->year;
            $m = $localDay->month;
            $d = $localDay->day;
            $dow = $localDay->dayOfWeekIso; // 1=Monday … 7=Sunday

            if (! $dayFilter($dow)) {
                continue;
            }

            $utcInstant = $this->resolveLocalToUtc($y, $m, $d, $anchorHour, $anchorMin, $anchorSec, $tz);

            if ($utcInstant === null) {
                // Spring-forward: nonexistent local time — skip this day (ADR-009).
                continue;
            }

            if ($utcInstant->gt($afterUtc)) {
                return $utcInstant;
            }
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Private — DST-aware local→UTC resolution
    // -----------------------------------------------------------------------

    /**
     * Convert a local calendar date + wall time to a UTC CarbonImmutable,
     * applying ADR-009 DST policies.
     *
     * Returns null when the local time is nonexistent (spring-forward skip).
     * Returns the earlier UTC instant when the local time is ambiguous (fall-back first-wins).
     */
    private function resolveLocalToUtc(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        int $second,
        string $tz,
    ): ?CarbonImmutable {
        $candidate = CarbonImmutable::create($year, $month, $day, $hour, $minute, $second, $tz);

        // Spring-forward check: PHP adjusts nonexistent local times forward.
        // If the resulting local hour no longer matches the requested hour,
        // the requested time does not exist on this day — skip it.
        if ($candidate->hour !== $hour) {
            return null;
        }

        $candidateUtc = $candidate->utc();

        // Fall-back check: when a local time is ambiguous, PHP typically resolves
        // to the post-transition (later UTC) instant. Per ADR-009, we want the
        // pre-transition (earlier UTC) instant — the first occurrence.
        //
        // Strategy: subtract exactly 1 hour from the UTC result and verify whether
        // the resulting instant also maps to the same local date/hour/minute.
        // Standard DST transitions are ±1 hour; this covers all MVP timezones.
        $oneHourEarlierUtc = $candidateUtc->subHour();
        $oneHourEarlierLocal = $oneHourEarlierUtc->setTimezone($tz);

        if (
            $oneHourEarlierLocal->year === $year
            && $oneHourEarlierLocal->month === $month
            && $oneHourEarlierLocal->day === $day
            && $oneHourEarlierLocal->hour === $hour
            && $oneHourEarlierLocal->minute === $minute
        ) {
            return $oneHourEarlierUtc;
        }

        return $candidateUtc;
    }
}
