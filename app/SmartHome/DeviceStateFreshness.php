<?php

declare(strict_types=1);

namespace App\SmartHome;

/**
 * How much a device's reported functional state can be trusted (DEV-01).
 *
 * ADR-037 §6 separates connectivity from functional state but says nothing
 * about age, because it left the freshness policy to whoever implemented it.
 * This is that discriminator, and it exists so the API can tell three
 * genuinely different situations apart instead of flattening them:
 *
 * - Fresh   — a value was observed within the TTL. Safe to present as current.
 * - Stale   — a value is known, but the last observation is older than the TTL
 *             (or the most recent read attempt failed). The value is the last
 *             one seen, explicitly NOT asserted as current.
 * - Unknown — no value is available at all. Nothing is reported.
 *
 * The distinction matters because the dishonest alternatives are both
 * available and both wrong: presenting a stale value as current, or
 * substituting a default (`power: false`) for a value never read. A lamp whose
 * state could not be read is not a lamp that is off.
 *
 * This is NOT a capability. It describes the read, not the device, and must
 * never be added to the capability vocabulary (ADR-037 §2, CAT-01).
 */
enum DeviceStateFreshness: string
{
    case Fresh = 'fresh';
    case Stale = 'stale';
    case Unknown = 'unknown';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
