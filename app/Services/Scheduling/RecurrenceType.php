<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

/**
 * Domain enum for schedule recurrence types.
 *
 * MVP shipped: once, daily, weekdays, weekly.
 * monthly is reserved for future implementation — NOT supported in MVP.
 * Passing monthly to RecurrenceService throws UnsupportedRecurrenceTypeException.
 */
enum RecurrenceType: string
{
    case Once = 'once';
    case Daily = 'daily';
    case Weekdays = 'weekdays';
    case Weekly = 'weekly';

    /** Reserved — not implemented in MVP. No logic, tests, or API until a future delivery phase. */
    case Monthly = 'monthly';

    /** Returns the MVP-allowed values (excludes monthly). */
    public static function mvpAllowed(): array
    {
        return [self::Once, self::Daily, self::Weekdays, self::Weekly];
    }

    public function isMvpSupported(): bool
    {
        return $this !== self::Monthly;
    }
}
