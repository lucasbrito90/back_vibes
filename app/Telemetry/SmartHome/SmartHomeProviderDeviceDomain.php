<?php

declare(strict_types=1);

namespace App\Telemetry\SmartHome;

/**
 * Bounded classification of the Home-Assistant-style entity domain (the
 * segment before the "." in an entity_id, e.g. "light.living_room" ->
 * "light") a Provider Boundary execution targets, for the
 * `ixora.provider.device_domain` span attribute only.
 *
 * Deliberately a Telemetry-layer enum, not a re-export of any constant
 * inside App\SmartHome\Adapters\HomeAssistantAdapter (its
 * ACTIONABLE_DOMAINS list) — this module never imports that domain class
 * (Dependency Rule: Telemetry depends only on Telemetry Contracts). The
 * caller (HomeAssistantAdapter) passes the raw domain string it already
 * computed (via its own domainFor()) to fromDomainSlug(), which normalizes
 * any value this Telemetry layer does not explicitly know about to the
 * reserved Other case — matching the enum-reservation convention already
 * used elsewhere in this Telemetry layer (e.g.
 * App\Telemetry\SmartHome\SmartHomeActionProvider::Future).
 *
 * This is a device-type CATEGORY (light/switch/media_player/fan), never a
 * specific device/entity identity — see backend-smart-home-provider-
 * boundary.md §"Security review" for why this is safe to export: it is
 * exactly as identifying as `ixora.action.provider` (a bounded, generic
 * classification), never a `provider_device_id` or `entity_id`.
 */
enum SmartHomeProviderDeviceDomain: string
{
    case Light = 'light';
    case Switch = 'switch';
    case MediaPlayer = 'media_player';
    case Fan = 'fan';
    case Other = 'other';

    public static function fromDomainSlug(string $slug): self
    {
        return self::tryFrom($slug) ?? self::Other;
    }
}
