<?php

declare(strict_types=1);

namespace App\Telemetry\SmartHome;

/**
 * Bounded classification of which provider a SceneActionJob execution
 * targets, for the `ixora.action.provider` span attribute and the
 * `provider` label on ixora.smart_home.action.* metrics.
 *
 * Deliberately a Telemetry-layer enum, not a re-export of
 * App\SmartHome\ProviderType — this module never imports that domain enum
 * (Dependency Rule: Telemetry depends only on Telemetry Contracts). The
 * caller (SceneActionJob) passes the raw provider slug string it already
 * has (`$connection->provider`) to fromProviderSlug(), which maps each
 * ADR-032 reserved slug to its own case via tryFrom() and normalizes any
 * genuinely unknown slug to the reserved Future case — matching the
 * enum-reservation convention already used elsewhere in this Telemetry
 * layer (e.g. App\Telemetry\SmartHome\SmartHomeActionType::Other).
 *
 * App\SmartHome\ProviderType reserves the same slug strings mirrored here
 * (home_assistant, tuya, philips_hue, alexa, google_home, matter). Future
 * remains the fallback only for slugs outside that reserved set.
 */
enum SmartHomeActionProvider: string
{
    case HomeAssistant = 'home_assistant';
    case Tuya = 'tuya';
    case PhilipsHue = 'philips_hue';
    case Alexa = 'alexa';
    case GoogleHome = 'google_home';
    case Matter = 'matter';
    case Future = 'future';

    public static function fromProviderSlug(string $slug): self
    {
        return self::tryFrom($slug) ?? self::Future;
    }
}
