<?php

declare(strict_types=1);

namespace App\PushNotifications;

use App\PushNotifications\Contracts\PushProvider as PushProviderContract;
use App\PushNotifications\Contracts\PushProviderResolver as PushProviderResolverContract;
use App\PushNotifications\Providers\FcmPushProvider;
use App\PushNotifications\Providers\NoopPushProvider;
use App\PushNotifications\PushProvider as PushProviderType;
use InvalidArgumentException;

/**
 * Resolves the concrete PushProvider for a given provider slug.
 *
 * Supported MVP slugs:
 *   - "fcm"  → FcmPushProvider  (production transport)
 *   - "noop" → NoopPushProvider (tests / local dev without Firebase credentials)
 *
 * APNs and WebPush are future providers and are not yet registered here.
 * Callers depend on PushProviderResolverContract, never on this concrete class
 * (ADR-017).
 *
 * Unsupported / unknown providers fail explicitly with InvalidArgumentException —
 * there is no silent fallback.
 */
final class PushProviderResolver implements PushProviderResolverContract
{
    public function __construct(
        private readonly FcmPushProvider $fcm,
        private readonly NoopPushProvider $noop,
    ) {}

    /**
     * @throws InvalidArgumentException When the provider is unknown or unsupported.
     */
    public function resolve(PushProviderType|string $provider): PushProviderContract
    {
        $slug = $provider instanceof PushProviderType ? $provider->value : $provider;

        return match ($slug) {
            'fcm' => $this->fcm,
            'noop' => $this->noop,
            default => throw new InvalidArgumentException(
                "Unsupported push provider [{$slug}]."
            ),
        };
    }
}
