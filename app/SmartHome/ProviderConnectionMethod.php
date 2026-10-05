<?php

declare(strict_types=1);

namespace App\SmartHome;

/**
 * Closed vocabulary of provider connection methods (ADR-045 Decision 2).
 *
 * Describes the mechanism used to establish a provider connection —
 * orthogonal to, and never merged with, execution capabilities
 * (ADR-036 Decision 2). Execution locus is derived from connection_method,
 * not from the provider slug (ADR-045 Decision 6).
 *
 * Only url_token (Home Assistant) and device_sdk (Google Home) are active.
 * The remaining cases are reserved for future providers and must not be
 * declared in config without a follow-up ADR.
 */
enum ProviderConnectionMethod: string
{
    case UrlToken = 'url_token';
    case Oauth2 = 'oauth2';
    case ApiKey = 'api_key';
    case DeviceSdk = 'device_sdk';
    case LocalDiscovery = 'local_discovery';

    /** Returns all valid connection method values as strings (for validation/config checks). */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
