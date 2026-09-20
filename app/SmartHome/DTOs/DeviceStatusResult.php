<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

use App\SmartHome\Canonical\DeviceState;
use App\SmartHome\DeviceStatus;

/**
 * Result of reading a single device's status from a provider.
 *
 * Returned by ProviderAdapter::readStatus(). Immutable.
 *
 * Carries two things that are NOT the same, and whose conflation ADR-037 §6
 * set out to end:
 *
 * - `status` is connectivity — reachable, unreachable, unknown. It answers
 *   "can I talk to this device".
 * - `state` is functional state — power, brightness, temperature. It answers
 *   "what is this device doing". Canonical, keyed by the same capability
 *   vocabulary commands use (CSDM-05).
 *
 * `raw_state` and `attributes` remain, provider-native and explicitly so, as
 * the boundary passthrough ADR-037 §1 keeps for metadata. They are not the
 * canonical contract and must not be read as one — `state` is. They stay
 * because the adapter contract and its conformance suite are written against
 * them; CSDM-07 decides their fate once nothing reads them.
 */
final readonly class DeviceStatusResult
{
    /**
     * @param  array<string, mixed>  $attributes  Provider-native attributes (e.g. HA entity attributes)
     * @param  DeviceState|null  $state  Canonical functional state; null when the provider could not be read
     */
    public function __construct(
        public string $provider_device_id,
        public DeviceStatus $status,
        public ?string $raw_state,
        public array $attributes,
        public ?string $last_changed,
        public ?DeviceState $state = null,
    ) {}
}
