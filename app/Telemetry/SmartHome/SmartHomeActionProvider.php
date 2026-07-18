<?php

declare(strict_types=1);

namespace App\Telemetry\SmartHome;

/**
 * Bounded classification of which provider a SmartHomeActionJob execution
 * targets, for the `ixora.action.provider` span attribute only.
 *
 * Deliberately a Telemetry-layer enum, not a re-export of
 * App\SmartHome\ProviderType — this module never imports that domain enum
 * (Dependency Rule: Telemetry depends only on Telemetry Contracts). The
 * caller (SmartHomeActionJob) passes the raw provider slug string it already
 * has (`$connection->provider`) to fromProviderSlug(), which normalizes any
 * value this Telemetry layer does not explicitly know about to the reserved
 * Future case — matching the enum-reservation convention already used
 * elsewhere in this Telemetry layer (e.g.
 * App\Telemetry\SmartHome\SmartHomeDispatchEntryPoint::Future).
 *
 * App\SmartHome\ProviderType itself reserves several not-yet-MVP-supported
 * provider slugs (tuya, philips_hue, alexa, google_home, matter) — every one
 * of those normalizes to Future here today, keeping this attribute's
 * cardinality bounded to exactly two values regardless of how many reserved
 * domain slugs exist.
 */
enum SmartHomeActionProvider: string
{
    case HomeAssistant = 'home_assistant';
    case Future = 'future';

    public static function fromProviderSlug(string $slug): self
    {
        return self::tryFrom($slug) ?? self::Future;
    }
}
