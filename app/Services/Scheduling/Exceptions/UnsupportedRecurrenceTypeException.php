<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Exceptions;

use App\Services\Scheduling\RecurrenceType;
use RuntimeException;

/**
 * Thrown when a RecurrenceType that is reserved but not yet implemented
 * is passed to RecurrenceService (e.g. monthly in MVP).
 */
final class UnsupportedRecurrenceTypeException extends RuntimeException
{
    public function __construct(RecurrenceType $type)
    {
        parent::__construct(
            "Recurrence type [{$type->value}] is not supported in the current MVP. "
            .'It is reserved for a future delivery phase.'
        );
    }
}
