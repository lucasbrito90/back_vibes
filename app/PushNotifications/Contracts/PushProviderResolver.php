<?php

declare(strict_types=1);

namespace App\PushNotifications\Contracts;

use App\PushNotifications\PushProvider as PushProviderType;

/**
 * Contract for resolving the concrete PushProvider for a given provider slug.
 *
 * Typed against in PushNotificationJob so callers depend on this contract, not
 * the concrete implementation (ADR-017). Allows mocking in tests without
 * requiring the concrete resolver to be non-final.
 */
interface PushProviderResolver
{
    /**
     * @throws \InvalidArgumentException When the provider is unknown or unsupported.
     */
    public function resolve(PushProviderType|string $provider): PushProvider;
}
