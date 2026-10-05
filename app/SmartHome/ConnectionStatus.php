<?php

declare(strict_types=1);

namespace App\SmartHome;

/**
 * Status values for a provider connection (ADR-045 Decision 5).
 *
 * Legacy values kept for backward compatibility with existing data and mobile clients
 * that may not yet recognise the extended vocabulary. New production paths use the
 * granular cases introduced in PRV-02.
 *
 * - Pending:                 Connection created; never tested.
 * - Connecting:              Test in progress (transient; set before async checks).
 * - Connected:               Last testConnection() or client health report succeeded.
 * - Unreachable:             Legacy: host/credentials failure (not yet differentiated).
 * - UnreachableHost:         Host/URL not reachable; verify network.
 * - UnreachableCredentials:  Host responded but rejected credentials (401/403).
 * - Unknown:                 Legacy: status cannot be determined or connection never tested.
 * - Revoked:                 Credentials were explicitly revoked/deleted at the provider.
 */
enum ConnectionStatus: string
{
    // PRV-02 new cases
    case Pending = 'pending';
    case Connecting = 'connecting';
    case UnreachableHost = 'unreachable_host';
    case UnreachableCredentials = 'unreachable_credentials';
    case Revoked = 'revoked';

    // Legacy cases (kept for backward compat)
    case Connected = 'connected';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';

    /** Returns all valid status values as strings (for validation rules). */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Returns the values a client is permitted to self-report via the health endpoint.
     * Transient and server-managed states are excluded.
     *
     * @return list<string>
     */
    public static function clientReportable(): array
    {
        return [
            self::Connected->value,
            self::UnreachableHost->value,
            self::UnreachableCredentials->value,
            self::Revoked->value,
        ];
    }
}
