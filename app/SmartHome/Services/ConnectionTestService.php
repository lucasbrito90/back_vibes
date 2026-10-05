<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Models\ProviderConnection;
use App\Models\ProviderConnectionAttempt;
use App\SmartHome\ConnectionStatus;
use App\SmartHome\DTOs\ConnectionHealth;
use App\SmartHome\DTOs\ConnectionTestResult;
use App\SmartHome\ProviderAdapterResolver;
use Illuminate\Support\Carbon;

/**
 * Executes or records a connectivity test for a provider connection (PRV-02).
 *
 * Two entry points:
 *  - testServerSide(): calls the adapter, records outcome, updates connection.
 *  - recordClientHealth(): persists a health report from the mobile client and
 *    updates connection. Input is treated as untrusted (validated by the caller).
 *
 * Security invariant: failure_reason stored in provider_connection_attempts MUST
 * NEVER contain credential values. Only HTTP status codes and generic messages.
 */
final class ConnectionTestService
{
    public function __construct(
        private readonly ProviderAdapterResolver $resolver,
    ) {}

    /**
     * Perform a server-side connectivity test via the registered adapter.
     * Updates connection.status and connection.last_tested_at.
     * Does NOT touch devices.
     */
    public function testServerSide(ProviderConnection $connection): ConnectionTestResult
    {
        $adapter = $this->resolver->forProvider($connection->provider);

        $health = $adapter->testConnection($connection);

        $status = $this->healthToStatus($health);
        $outcome = $health->reachable ? 'success' : ($this->isCredentialError($health) ? 'failure_credentials' : 'failure_host');
        $failureReason = $this->safeFailureReason($health);

        $attempt = ProviderConnectionAttempt::create([
            'provider_connection_id' => $connection->id,
            'source' => 'server',
            'outcome' => $outcome,
            'failure_reason' => $failureReason,
            'latency_ms' => $health->latency_ms,
            'observed_at' => now(),
        ]);

        $connection->status = $status->value;
        $connection->last_tested_at = now();
        $connection->save();

        return new ConnectionTestResult(
            newStatus: $status,
            outcome: $outcome,
            latencyMs: $health->latency_ms,
            failureReason: $failureReason,
            attemptId: $attempt->id,
        );
    }

    /**
     * Record a health report submitted by the mobile client.
     * Updates connection.status and connection.last_tested_at.
     * Does NOT touch devices.
     *
     * @param  string  $status  One of ConnectionStatus::clientReportable() values.
     */
    public function recordClientHealth(
        ProviderConnection $connection,
        string $status,
        ?string $failureReason,
        Carbon $observedAt,
    ): ConnectionTestResult {
        $outcome = match ($status) {
            ConnectionStatus::Connected->value => 'success',
            ConnectionStatus::UnreachableCredentials->value => 'failure_credentials',
            default => 'failure_host',
        };

        $attempt = ProviderConnectionAttempt::create([
            'provider_connection_id' => $connection->id,
            'source' => 'client',
            'outcome' => $outcome,
            'failure_reason' => $failureReason,
            'latency_ms' => null,
            'observed_at' => $observedAt,
        ]);

        $connection->status = $status;
        $connection->last_tested_at = $observedAt;
        $connection->save();

        return new ConnectionTestResult(
            newStatus: ConnectionStatus::from($status),
            outcome: $outcome,
            latencyMs: null,
            failureReason: $failureReason,
            attemptId: $attempt->id,
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    private function healthToStatus(ConnectionHealth $health): ConnectionStatus
    {
        if ($health->reachable) {
            return ConnectionStatus::Connected;
        }

        return $this->isCredentialError($health)
            ? ConnectionStatus::UnreachableCredentials
            : ConnectionStatus::UnreachableHost;
    }

    private function isCredentialError(ConnectionHealth $health): bool
    {
        return $health->status_code !== null && in_array($health->status_code, [401, 403], true);
    }

    /**
     * Build a failure reason that is safe to persist: only HTTP status codes,
     * never credential values or raw error messages that might contain secrets.
     */
    private function safeFailureReason(ConnectionHealth $health): ?string
    {
        if ($health->reachable) {
            return null;
        }

        if ($health->status_code !== null) {
            return "HTTP {$health->status_code}";
        }

        return 'Host unreachable';
    }
}
