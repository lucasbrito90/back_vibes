<?php

declare(strict_types=1);

namespace App\SmartHome;

/**
 * Resolves per-provider HTTP request timeouts from smart_home config.
 *
 * Falls back to the Home Assistant timeout when a registered provider has no
 * explicit entry — preserving the pre-v1.4.0 HA default for all adapters.
 */
final class ProviderRequestTimeout
{
    public static function forSlug(string $provider): int
    {
        $configured = config("smart_home.providers.{$provider}.timeout");

        if ($configured !== null) {
            return (int) $configured;
        }

        return (int) config('smart_home.providers.home_assistant.timeout', 10);
    }
}
