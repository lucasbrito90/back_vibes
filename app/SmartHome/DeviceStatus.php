<?php

declare(strict_types=1);

namespace App\SmartHome;

/**
 * Normalised device status values for the IXORA device registry.
 *
 * MVP values: online, offline, unknown.
 * Status is refreshed from the provider on sync; if the provider is
 * unreachable, all its devices flip to Unknown (not retained as Online).
 *
 * References: ADR-014 §Status model, spec.md §7 Device status.
 */
enum DeviceStatus: string
{
    case Online = 'online';
    case Offline = 'offline';
    case Unknown = 'unknown';

    /** Returns all valid status value strings (for use in validation rules). */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
