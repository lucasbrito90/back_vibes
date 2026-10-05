<?php

declare(strict_types=1);

namespace App\SmartHome\Adapters;

use App\Models\ProviderConnection;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\DeviceState;
use App\SmartHome\Canonical\LegacyCapabilitiesReader;
use App\SmartHome\Contracts\ProviderAdapter;
use App\SmartHome\DeviceStatus;
use App\SmartHome\DeviceType;
use App\SmartHome\DTOs\ActionResult;
use App\SmartHome\DTOs\ConnectionHealth;
use App\SmartHome\DTOs\DeviceStatusResult;
use App\SmartHome\DTOs\ProviderDevice;
use App\SmartHome\Exceptions\ProviderConnectionException;
use App\SmartHome\Exceptions\UnsupportedSmartHomeActionException;

/**
 * In-memory ProviderAdapter for automated tests only (ADR-032 decision A / T10).
 *
 * Never registered in production config — tests bind this class via
 * config(['smart_home.adapters.fake' => FakeProviderAdapter::class]) and
 * app()->singleton(FakeProviderAdapter::class). Scenario flags are mutable
 * per test instance; prefer `new FakeProviderAdapter()` or reset() between
 * cases to avoid state leaking across tests when resolved as a singleton.
 */
final class FakeProviderAdapter implements ProviderAdapter
{
    public const PROVIDER_SLUG = 'fake';

    /** @var list<ProviderDevice> */
    private array $devices;

    /** @var null|'unreachable'|'bad_status' */
    private ?string $listDevicesFailure = null;

    private bool $readStatusSimulateFailure = false;

    /** @var null|'unreachable'|'bad_status' */
    private ?string $testConnectionFailure = null;

    /** @var null|'transport'|'http' */
    private ?string $executeActionFailure = null;

    /** @var list<string> */
    private array $supportedActions = ['turn_on', 'turn_off', 'toggle', 'set_brightness'];

    public function __construct()
    {
        $this->devices = $this->defaultDevices();
    }

    /**
     * @param  list<ProviderDevice>  $devices
     */
    public function withDevices(array $devices): self
    {
        $this->devices = $devices;

        return $this;
    }

    public function reset(): self
    {
        $this->devices = $this->defaultDevices();
        $this->listDevicesFailure = null;
        $this->readStatusSimulateFailure = false;
        $this->testConnectionFailure = null;
        $this->executeActionFailure = null;
        $this->supportedActions = ['turn_on', 'turn_off', 'toggle', 'set_brightness'];

        return $this;
    }

    public function simulateListDevicesUnreachable(): self
    {
        $this->listDevicesFailure = 'unreachable';

        return $this;
    }

    public function simulateListDevicesBadStatus(): self
    {
        $this->listDevicesFailure = 'bad_status';

        return $this;
    }

    public function simulateReadStatusFailure(): self
    {
        $this->readStatusSimulateFailure = true;

        return $this;
    }

    public function simulateTestConnectionUnreachable(): self
    {
        $this->testConnectionFailure = 'unreachable';

        return $this;
    }

    public function simulateTestConnectionBadStatus(): self
    {
        $this->testConnectionFailure = 'bad_status';

        return $this;
    }

    public function simulateExecuteActionTransportFailure(): self
    {
        $this->executeActionFailure = 'transport';

        return $this;
    }

    public function simulateExecuteActionHttpFailure(): self
    {
        $this->executeActionFailure = 'http';

        return $this;
    }

    /**
     * @param  list<string>  $actions
     */
    public function withSupportedActions(array $actions): self
    {
        $this->supportedActions = $actions;

        return $this;
    }

    public function listDevices(ProviderConnection $connection): array
    {
        return match ($this->listDevicesFailure) {
            'unreachable' => throw ProviderConnectionException::unreachable(self::PROVIDER_SLUG),
            'bad_status' => throw ProviderConnectionException::badStatus(self::PROVIDER_SLUG, 502),
            default => $this->devices,
        };
    }

    public function readStatus(ProviderConnection $connection, string $deviceId): DeviceStatusResult
    {
        if ($this->readStatusSimulateFailure) {
            return new DeviceStatusResult(
                provider_device_id: $deviceId,
                status: DeviceStatus::Unknown,
                raw_state: null,
                attributes: [],
                last_changed: null,
            );
        }

        foreach ($this->devices as $device) {
            if ($device->provider_device_id === $deviceId) {
                return new DeviceStatusResult(
                    provider_device_id: $deviceId,
                    status: $device->status,
                    raw_state: $device->metadata['raw_state'] ?? null,
                    attributes: $device->metadata,
                    last_changed: null,
                    state: $this->canonicalState($device),
                );
            }
        }

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
        if (! in_array($action, $this->supportedActions, true)) {
            throw UnsupportedSmartHomeActionException::forAction($action);
        }

        return match ($this->executeActionFailure) {
            'transport' => new ActionResult(
                success: false,
                status_code: null,
                response: null,
                error_message: 'Provider connection failed.',
            ),
            'http' => new ActionResult(
                success: false,
                status_code: 500,
                response: null,
                error_message: 'Provider returned status 500.',
            ),
            default => new ActionResult(
                success: true,
                status_code: 200,
                response: ['entity_id' => $deviceId, 'action' => $action],
                error_message: null,
            ),
        };
    }

    public function testConnection(ProviderConnection $connection): ConnectionHealth
    {
        return match ($this->testConnectionFailure) {
            'unreachable' => new ConnectionHealth(
                reachable: false,
                status_code: null,
                latency_ms: 1,
                error_message: 'Provider connection failed.',
            ),
            'bad_status' => new ConnectionHealth(
                reachable: false,
                status_code: 401,
                latency_ms: 1,
                error_message: 'Provider returned status 401.',
            ),
            default => new ConnectionHealth(
                reachable: true,
                status_code: 200,
                latency_ms: 1,
                error_message: null,
            ),
        };
    }

    /**
     * The device's functional state in the canonical vocabulary (ADR-037 §6).
     *
     * Exists so the state pipeline can be exercised provider-independently:
     * the normalisation guarantee ("the same functional state has the same
     * canonical shape whatever the provider") is only testable if more than one
     * adapter produces canonical state. Before DEV-01 this fake returned
     * `state: null`, so that property was unverifiable by construction.
     *
     * Unlike Home Assistant, this fake declares no native scale of its own —
     * `metadata['brightness']` is already the canonical 0-100 percent, so there
     * is deliberately no conversion here to mirror. A real adapter converts at
     * its own boundary; a fake with an invented scale would only be testing the
     * invention.
     */
    private function canonicalState(ProviderDevice $device): DeviceState
    {
        $declared = (new LegacyCapabilitiesReader)->read($device->capabilities);
        $capabilities = $declared?->capabilities ?? [];

        $values = [];

        $power = match ($device->metadata['raw_state'] ?? null) {
            'on' => true,
            'off' => false,
            default => null,
        };

        if ($power !== null && isset($capabilities[CapabilityId::Power->value])) {
            $values[CapabilityId::Power->value] = $power;
        }

        $brightness = $device->metadata['brightness'] ?? null;

        if (isset($capabilities[CapabilityId::Brightness->value]) && (is_int($brightness) || is_float($brightness))) {
            $values[CapabilityId::Brightness->value] = (int) round((float) $brightness);
        }

        return DeviceState::of($values, $capabilities);
    }

    /**
     * @return list<ProviderDevice>
     */
    private function defaultDevices(): array
    {
        return [
            new ProviderDevice(
                provider_device_id: 'fake.light.living',
                name: 'Fake Living Light',
                type: DeviceType::Lighting->value,
                status: DeviceStatus::Online,
                metadata: ['raw_state' => 'on', 'brightness' => 65],
                last_seen_at: null,
                capabilities: [
                    'can_turn_on' => [],
                    'can_turn_off' => [],
                    'can_toggle' => [],
                    'can_set_brightness' => ['min' => 0, 'max' => 255, 'step' => 1],
                ],
            ),
            new ProviderDevice(
                provider_device_id: 'fake.switch.kitchen',
                name: 'Fake Kitchen Switch',
                type: DeviceType::Switchable->value,
                status: DeviceStatus::Online,
                metadata: ['raw_state' => 'off'],
                last_seen_at: null,
                capabilities: [
                    'can_turn_on' => [],
                    'can_turn_off' => [],
                    'can_toggle' => [],
                ],
            ),
        ];
    }
}
