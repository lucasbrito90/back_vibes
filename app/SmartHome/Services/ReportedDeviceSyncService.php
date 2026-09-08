<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Models\Device;
use App\Models\ProviderConnection;
use App\SmartHome\DeviceStatus;
use App\SmartHome\DTOs\SyncResult;
use Illuminate\Support\Facades\DB;

/**
 * Persists a client-reported device catalog (ADR-036 Decision 7 / P05).
 *
 * The mobile runtime discovers devices locally (a provider without
 * server_side_execution has nothing the backend can pull) and pushes an
 * already-validated, already-owned catalog here — see
 * SyncReportedDevicesRequest for shape/vocabulary validation and
 * ProviderConnectionController::syncReportedDevices() for the ownership
 * check. This class only persists.
 *
 * Mirrors the upsert-by-(provider_connection_id, provider_device_id) and
 * "absent from this report -> offline" semantics of the server-pull path
 * (ProviderDeviceSyncService), but is a deliberately SEPARATE
 * implementation: ProviderDeviceSyncService is an ADR-032 §D.1 boundary
 * file with its own passing Home Assistant regression suite, and P05 does
 * not touch it — zero risk of regressing that path by construction.
 */
final class ReportedDeviceSyncService
{
    /**
     * @param  list<array{provider_device_id: string, name: string, type: string|null, capabilities: array<string, mixed>|null}>  $devices
     */
    public function syncReported(ProviderConnection $connection, array $devices): SyncResult
    {
        return DB::transaction(function () use ($connection, $devices): SyncResult {
            [$created, $updated] = $this->upsertDevices($connection, $devices);
            $offline = $this->markAbsentDevicesOffline($connection, $devices);

            return new SyncResult(
                provider_connection_id: $connection->id,
                synced: count($devices),
                created: $created,
                updated: $updated,
                offline: $offline,
                // Connection status/last_tested_at semantics for a client-reported
                // provider are client-reported health, not server-derived
                // (ADR-036 Decision 4 §2) — that reporting path is a separate
                // task. This service echoes the connection's current status,
                // it never sets it.
                status: $connection->status,
            );
        });
    }

    /**
     * @param  list<array{provider_device_id: string, name: string, type: string|null, capabilities: array<string, mixed>|null}>  $devices
     * @return array{int, int}
     */
    private function upsertDevices(ProviderConnection $connection, array $devices): array
    {
        $created = 0;
        $updated = 0;

        foreach ($devices as $reported) {
            // GH-COMPLIANCE (https://trello.com/c/4pLU8Vt4): provider_device_id
            // persisted below is the raw provider device identifier reported by
            // the mobile runtime. For a provider without server_side_execution
            // (Google Home today — ADR-036 §1-3), this IS the Google device ID.
            // This write is one of the boundaries explicitly authorized to
            // persist it (P05, alongside P07/P11) as a temporary DEVELOPMENT
            // decision — not a confirmed compliance solution. No other part of
            // the domain should write provider_device_id for a client-reported
            // provider.
            $device = Device::updateOrCreate(
                [
                    'provider_connection_id' => $connection->id,
                    'provider_device_id' => $reported['provider_device_id'],
                ],
                [
                    'user_id' => $connection->user_id,
                    'name' => $reported['name'],
                    'type' => $reported['type'] ?? null,
                    'provider' => $connection->provider,
                    // A device present in a freshly reported catalog was just
                    // observed by the mobile runtime — online, by construction.
                    'status' => DeviceStatus::Online->value,
                    'metadata' => [],
                    'last_seen_at' => now(),
                    'capabilities' => $reported['capabilities'] ?? null,
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
     * Any device belonging to this connection that was NOT in the reported
     * catalog is considered absent -> mark status = offline. Same semantics
     * as ProviderDeviceSyncService::markAbsentDevicesOffline(), duplicated
     * rather than shared for the reason documented on the class.
     *
     * @param  list<array{provider_device_id: string, name: string, type: string|null, capabilities: array<string, mixed>|null}>  $devices
     */
    private function markAbsentDevicesOffline(ProviderConnection $connection, array $devices): int
    {
        $presentIds = array_map(
            static fn (array $d): string => $d['provider_device_id'],
            $devices,
        );

        return Device::where('provider_connection_id', $connection->id)
            ->whereNotIn('provider_device_id', $presentIds)
            ->where('status', '!=', DeviceStatus::Offline->value)
            ->update(['status' => DeviceStatus::Offline->value]);
    }
}
