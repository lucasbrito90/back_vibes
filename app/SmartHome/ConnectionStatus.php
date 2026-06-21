<?php

declare(strict_types=1);

namespace App\SmartHome;

/**
 * Status values for a provider connection.
 *
 * - Connected: last testConnection() succeeded.
 * - Unreachable: last testConnection() failed (provider down or credentials invalid).
 * - Unknown: connection has never been tested or status cannot be determined.
 */
enum ConnectionStatus: string
{
    case Connected = 'connected';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';

    /** Returns all valid status values as strings (for validation rules). */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
