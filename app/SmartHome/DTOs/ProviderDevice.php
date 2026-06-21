<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

use App\SmartHome\DeviceStatus;
use Illuminate\Support\Carbon;

/**
 * A normalised device discovered on a provider, as returned by
 * ProviderAdapter::listDevices(). This is the provider-agnostic shape the sync
 * layer upserts into the devices table — it is NOT the Eloquent model.
 *
 * Immutable.
 */
final readonly class ProviderDevice
{
    /**
     * @param  array<string, mixed>  $metadata  Provider-specific attributes (domain, raw_state, supported_features, …)
     */
    public function __construct(
        public string $provider_device_id,
        public string $name,
        public string $type,
        public DeviceStatus $status,
        public array $metadata,
        public ?Carbon $last_seen_at,
    ) {}
}
