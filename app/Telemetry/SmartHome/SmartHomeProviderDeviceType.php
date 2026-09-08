<?php

declare(strict_types=1);

namespace App\Telemetry\SmartHome;

/**
 * Bounded classification of the Ixora-normalised device type a Provider
 * Boundary execution targets, for the `ixora.provider.device_type` span
 * attribute only.
 *
 * Deliberately a Telemetry-layer enum, not a re-export of
 * App\SmartHome\DeviceType — this module never imports that domain enum
 * (Dependency Rule: Telemetry depends only on Telemetry Contracts). The
 * caller (HomeAssistantAdapter) passes the raw type slug it already
 * computed (via its own mapDeviceType()) to fromTypeSlug(), which
 * normalizes any value this Telemetry layer does not explicitly know about
 * to the reserved Other case — matching the enum-reservation convention
 * already used elsewhere in this Telemetry layer (e.g.
 * App\Telemetry\SmartHome\SmartHomeProviderDeviceDomain::Other).
 *
 * This is a device-type CATEGORY (lighting/switchable/media/ventilation),
 * never a specific device/entity identity — complementary to
 * ixora.provider.device_domain (HA entity domain), not a replacement.
 */
enum SmartHomeProviderDeviceType: string
{
    case Lighting = 'lighting';
    case Switchable = 'switchable';
    case Media = 'media';
    case Ventilation = 'ventilation';
    case Other = 'other';

    public static function fromTypeSlug(string $slug): self
    {
        return self::tryFrom($slug) ?? self::Other;
    }
}
