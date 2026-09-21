<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

/**
 * Whether a capability can be read, commanded, or both (ADR-037 §2).
 *
 * This is what makes read-only capabilities representable — `energy`,
 * `current_temperature` — without inventing a parallel "sensor" concept.
 * There is no third category.
 */
enum Access: string
{
    case Read = 'read';
    case Write = 'write';
    case ReadWrite = 'read_write';

    /** Whether this access level admits commanding the capability. */
    public function permitsWrite(): bool
    {
        return $this === self::Write || $this === self::ReadWrite;
    }
}
