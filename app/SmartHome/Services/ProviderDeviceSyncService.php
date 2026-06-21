<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Models\Device;
use App\Models\ProviderConnection;
use App\SmartHome\ConnectionStatus;
use App\SmartHome\DeviceStatus;
use App\SmartHome\DTOs\ProviderDevice;
use App\SmartHome\DTOs\SyncResult;
use App\SmartHome\Exceptions\ProviderConnectionException;
use App\SmartHome\ProviderAdapterResolver;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Orchestrates a full device sync for a single provider connection.
 *
 * Responsibilities:
 * - Resolve the correct adapter via ProviderAdapterResolver.
 * - Call adapter->listDevices() to fetch provider state.
 * - Upsert each returned device into the devices table (no duplicates).
 * - Mark devices absent from the provider response as offline.
 * - Update provider connection status (connected / unreachable).
 * - Return a SyncResult summary.
 *
 * Never calls the provider outside of listDevices(). Does not dispatch jobs,
 * touch the queue, or modify Scheduler code.
 */
final class ProviderDeviceSyncService
{
    public function __construct(
        private readonly ProviderAdapterResolver $resolver,
    ) {}

    /**
     * @throws ProviderConnectionException Bubbled up after marking the connection
     *                                     unreachable and all its devices unknown, so the controller can return 502.
     */
    public function sync(ProviderConnection $connection): SyncResult
    {
        $adapter = $this->resolver->forProvider($connection->provider);

        try {
            $providerDevices = $adapter->listDevices($connection);
        } catch (Throwable $e) {
            $this->markConnectionUnreachable($connection);

            throw $e;
        }

        return DB::transaction(function () use ($connection, $providerDevices): SyncResult {
            [$created, $updated] = $this->upsertDevices($connection, $providerDevices);

            $offline = $this->markAbsentDevicesOffline($connection, $providerDevices);

            $this->markConnectionConnected($connection);

            return new SyncResult(
                provider_connection_id: $connection->id,
                synced: count($providerDevices),
                created: $created,
                updated: $updated,
                offline: $offline,
                status: ConnectionStatus::Connected->value,
            );
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upsert each provider device into the devices table.
     *
     * The unique key is (provider_connection_id, provider_device_id).
     * Returns [created_count, updated_count].
     *
     * @param  list<ProviderDevice>  $providerDevices
     * @return array{int, int}
     */
    private function upsertDevices(ProviderConnection $connection, array $providerDevices): array
    {
        $created = 0;
        $updated = 0;

        foreach ($providerDevices as $dto) {
            $device = Device::updateOrCreate(
                [
                    'provider_connection_id' => $connection->id,
                    'provider_device_id' => $dto->provider_device_id,
                ],
                [
                    'user_id' => $connection->user_id,
                    'name' => $dto->name,
                    'type' => $dto->type,
                    'provider' => $connection->provider,
                    'status' => $dto->status->value,
                    'metadata' => $dto->metadata,
                    'last_seen_at' => $dto->last_seen_at,
                ]
            );

            if ($device->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        return [$created, $updated];
    }

    /**
     * Any device belonging to this connection that was NOT in the provider
     * response is considered absent → mark status = offline.
     *
     * Returns the count of devices marked offline.
     *
     * @param  list<ProviderDevice>  $providerDevices
     */
    private function markAbsentDevicesOffline(ProviderConnection $connection, array $providerDevices): int
    {
        $presentIds = array_map(
            static fn (ProviderDevice $d): string => $d->provider_device_id,
            $providerDevices,
        );

        return Device::where('provider_connection_id', $connection->id)
            ->whereNotIn('provider_device_id', $presentIds)
            ->where('status', '!=', DeviceStatus::Offline->value)
            ->update(['status' => DeviceStatus::Offline->value]);
    }

    private function markConnectionConnected(ProviderConnection $connection): void
    {
        $connection->status = ConnectionStatus::Connected->value;
        $connection->last_tested_at = now();
        $connection->save();
    }

    private function markConnectionUnreachable(ProviderConnection $connection): void
    {
        $connection->status = ConnectionStatus::Unreachable->value;
        $connection->save();

        Device::where('provider_connection_id', $connection->id)
            ->update(['status' => DeviceStatus::Unknown->value]);
    }
}
