<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Models\Device;
use App\SmartHome\DeviceStateFreshness;
use App\SmartHome\DTOs\DeviceStateSnapshot;
use App\SmartHome\ProviderAdapterResolver;
use App\SmartHome\ProviderDescriptorRegistry;
use App\SmartHome\ProviderExecutionCapability;
use InvalidArgumentException;
use Throwable;

/**
 * Connects `ProviderAdapter::readStatus()` to the persisted canonical state
 * (DEV-01).
 *
 * Before this class, `readStatus()` and `HomeAssistantCanonicalMapper::toDeviceState()`
 * both existed, were both tested, and had no caller anywhere in `app/` — the
 * canonical state pipeline was built and never plugged in. This is the plug.
 *
 * Freshness policy: read-through with a TTL, persisting to `devices.state`.
 * Reading is split in two deliberately, because the two call sites have
 * opposite cost profiles:
 *
 * - {@see Device::stateSnapshot()} presents what is already stored and contacts
 *   nobody. The device LIST uses this, so listing N devices costs zero provider
 *   calls — the N-request risk named in the DEV-01 card simply does not arise.
 * - {@see refreshIfStale()} contacts the provider, and only when the stored
 *   value is absent or aged past the TTL. The device DETAIL uses this: one
 *   device, one call at most, and nothing at all when a recent read is on hand.
 *
 * What this class will NOT do is invent state. A provider without
 * `server_side_execution` (google_home) is never polled, because there is no
 * adapter to poll and pretending otherwise would be the "fake server-side"
 * the card forbids — its state can only arrive as an inbound client report.
 */
final class DeviceStateService
{
    /** Fallback when config is absent; see config('smart_home.device_state.ttl_seconds'). */
    public const DEFAULT_TTL_SECONDS = 60;

    public function __construct(
        private readonly ProviderAdapterResolver $resolver,
        private readonly ProviderDescriptorRegistry $descriptors,
    ) {}

    /**
     * Refresh from the provider when the stored state is absent or stale, then
     * report it.
     *
     * Returns the stored snapshot untouched when it is still fresh, when the
     * provider cannot be read server-side, or when the read fails — in the last
     * case the previously known value is reported as `stale` rather than
     * discarded, because "this is the last value seen, and it is not current"
     * is true, while erasing it would lose information the client can use.
     */
    public function refreshIfStale(Device $device): DeviceStateSnapshot
    {
        $ttl = $this->ttlSeconds();
        $snapshot = $device->stateSnapshot($ttl);

        if ($snapshot->freshness === DeviceStateFreshness::Fresh) {
            return $snapshot;
        }

        if (! $this->canReadServerSide($device)) {
            return $snapshot;
        }

        return $this->readFromProvider($device) ?? $snapshot;
    }

    /**
     * Read the device's functional state from its provider and persist it.
     *
     * Returns null when the read produced nothing persistable, leaving any
     * previously stored value and its `state_read_at` untouched: a failed read
     * must not stamp a fresh observation time, or a failure would masquerade as
     * a successful one.
     *
     * `readStatus()` never throws per the adapter contract, and a provider that
     * violates that is contained here rather than surfaced as a 500 — the
     * functional contract degrades to `unknown`, which is what the card means
     * by not letting infrastructure exceptions leak through it.
     */
    public function readFromProvider(Device $device): ?DeviceStateSnapshot
    {
        $connection = $device->providerConnection;

        if ($connection === null) {
            return null;
        }

        try {
            $adapter = $this->resolver->forProvider($device->provider);
            $result = $adapter->readStatus($connection, $device->provider_device_id);
        } catch (Throwable) {
            return null;
        }

        $state = $result->state;

        if ($state === null) {
            return null;
        }

        // An empty read IS persisted. The observation happened, and recording
        // it stops a device that genuinely reports no canonical value from
        // being re-polled on every request. It still presents as `unknown`,
        // because there is no value to report.
        $device->state = $state->values;
        $device->state_read_at = $state->readAt;
        $device->save();

        return DeviceStateSnapshot::of($state, true);
    }

    /**
     * Whether the backend may read this device's state itself.
     *
     * The gate is `server_side_execution`, not `state_read`. Both providers
     * declare `state_read` — google_home's state IS readable, just not by the
     * backend (ADR-036 §1-3): the Home APIs have no server-reachable surface,
     * so the mobile runtime reads it and reports it inward. `server_side_execution`
     * is the capability that actually answers "can the backend do provider work
     * with stored credentials", which is the question being asked here. Same
     * discriminator PRV-02 uses to split `/test` from `/report-health`.
     */
    private function canReadServerSide(Device $device): bool
    {
        try {
            $descriptor = $this->descriptors->forSlug($device->provider);
        } catch (InvalidArgumentException) {
            return false;
        }

        foreach ($descriptor->executionCapabilities as $capability) {
            if ($capability === ProviderExecutionCapability::ServerSideExecution) {
                return true;
            }
        }

        return false;
    }

    private function ttlSeconds(): int
    {
        $configured = config('smart_home.device_state.ttl_seconds', self::DEFAULT_TTL_SECONDS);

        return is_int($configured) && $configured >= 0 ? $configured : self::DEFAULT_TTL_SECONDS;
    }
}
