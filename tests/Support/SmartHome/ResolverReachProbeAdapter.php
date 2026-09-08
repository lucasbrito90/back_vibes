<?php

declare(strict_types=1);

namespace Tests\Support\SmartHome;

use App\Models\ProviderConnection;
use App\SmartHome\Contracts\ProviderAdapter;
use App\SmartHome\DeviceStatus;
use App\SmartHome\DTOs\ActionResult;
use App\SmartHome\DTOs\ConnectionHealth;
use App\SmartHome\DTOs\DeviceStatusResult;

/**
 * Probe adapter: sets {@see self::$constructed} when the container instantiates it.
 *
 * Used by SceneActionJob tests to assert the resolver/registry path was not reached
 * (final resolver/registry classes cannot be Mockery-spied under PHP 8.4).
 */
final class ResolverReachProbeAdapter implements ProviderAdapter
{
    public static bool $constructed = false;

    public static function reset(): void
    {
        self::$constructed = false;
    }

    public function __construct()
    {
        self::$constructed = true;
    }

    public function listDevices(ProviderConnection $connection): array
    {
        return [];
    }

    public function readStatus(ProviderConnection $connection, string $deviceId): DeviceStatusResult
    {
        return new DeviceStatusResult(
            provider_device_id: $deviceId,
            status: DeviceStatus::Unknown,
            raw_state: null,
            attributes: [],
            last_changed: null,
        );
    }

    public function executeAction(
        ProviderConnection $connection,
        string $deviceId,
        string $action,
        array $parameters = []
    ): ActionResult {
        return new ActionResult(success: true, status_code: 200, response: null, error_message: null);
    }

    public function testConnection(ProviderConnection $connection): ConnectionHealth
    {
        return new ConnectionHealth(reachable: true, status_code: 200, latency_ms: 0, error_message: null);
    }
}
