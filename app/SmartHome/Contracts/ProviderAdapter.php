<?php

declare(strict_types=1);

namespace App\SmartHome\Contracts;

use App\Models\ProviderConnection;
use App\SmartHome\DTOs\ActionResult;
use App\SmartHome\DTOs\ConnectionHealth;
use App\SmartHome\DTOs\DeviceStatusResult;
use App\SmartHome\DTOs\ProviderDevice;
use App\SmartHome\Exceptions\ProviderConnectionException;
use App\SmartHome\Exceptions\UnsupportedSmartHomeActionException;

/**
 * Normalised contract every Smart Home provider adapter implements.
 *
 * The interface decouples vibe-action / device-sync logic from provider
 * specifics (ADR-012). A new provider is a new adapter only — callers depend on
 * this contract, never on a concrete adapter.
 *
 * Error handling policy:
 * - testConnection(): never throws; returns ConnectionHealth(reachable=false) on failure.
 * - readStatus(): never throws; returns DeviceStatusResult(status=Unknown) on failure.
 * - executeAction(): never throws for transport/HTTP failures (returns failed
 *   ActionResult); throws UnsupportedSmartHomeActionException for unmappable actions.
 * - listDevices(): throws ProviderConnectionException when the provider is
 *   unreachable or returns a non-2xx response (so sync can mark devices unknown).
 */
interface ProviderAdapter
{
    /**
     * List all actionable devices exposed by this connection.
     *
     * @return list<ProviderDevice>
     *
     * @throws ProviderConnectionException When the provider is unreachable or errors.
     */
    public function listDevices(ProviderConnection $connection): array;

    /**
     * Read the current status of a single device.
     */
    public function readStatus(ProviderConnection $connection, string $deviceId): DeviceStatusResult;

    /**
     * Execute an action against a device.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws UnsupportedSmartHomeActionException When the action is not mappable.
     */
    public function executeAction(
        ProviderConnection $connection,
        string $deviceId,
        string $action,
        array $parameters = []
    ): ActionResult;

    /**
     * Verify the connection credentials are reachable and report health.
     */
    public function testConnection(ProviderConnection $connection): ConnectionHealth;
}
