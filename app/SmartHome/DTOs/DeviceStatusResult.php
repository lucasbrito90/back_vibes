<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

use App\SmartHome\DeviceStatus;

/**
 * Normalised result of reading a single device's status from a provider.
 *
 * Returned by ProviderAdapter::readStatus(). Immutable.
 */
final readonly class DeviceStatusResult
{
    /**
     * @param  array<string, mixed>  $attributes  Provider-native attributes (e.g. HA entity attributes)
     */
    public function __construct(
        public string $provider_device_id,
        public DeviceStatus $status,
        public ?string $raw_state,
        public array $attributes,
        public ?string $last_changed,
    ) {}
}
