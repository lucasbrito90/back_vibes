<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when recurrence_config is structurally invalid for the given RecurrenceType.
 *
 * weekly requires recurrence_config.days_of_week: a non-empty array of ISO weekday
 * integers in the range 1 (Monday) … 7 (Sunday).
 */
final class InvalidRecurrenceConfigurationException extends InvalidArgumentException
{
    public static function weeklyMissingDaysOfWeek(): self
    {
        return new self(
            'weekly recurrence requires recurrence_config with a non-empty days_of_week array.'
        );
    }

    public static function weeklyInvalidDayValue(mixed $value): self
    {
        $display = is_scalar($value) ? (string) $value : gettype($value);

        return new self(
            "weekly recurrence days_of_week contains an invalid value [{$display}]. "
            .'Each day must be an ISO weekday integer between 1 (Monday) and 7 (Sunday).'
        );
    }
}
