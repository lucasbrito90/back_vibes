<?php

declare(strict_types=1);

namespace App\PushNotifications;

use App\PushNotifications\Contracts\PushProvider as PushProviderContract;
use App\PushNotifications\Providers\FcmPushProvider;
use App\PushNotifications\PushProvider as PushProviderType;
use InvalidArgumentException;

/**
 * Resolves the concrete PushProvider for a given provider slug.
 *
 * MVP supports FCM only. APNs and WebPush are future providers and are not yet
 * registered here. Callers depend on the PushProvider contract, never a concrete
 * implementation (ADR-017).
 *
 * Unsupported / unknown providers fail explicitly with InvalidArgumentException —
 * there is no silent fallback.
 */
final class PushProviderResolver
{
    public function __construct(
        private readonly FcmPushProvider $fcm,
    ) {}

    /**
     * @throws InvalidArgumentException When the provider is unknown or unsupported.
     */
    public function resolve(PushProviderType|string $provider): PushProviderContract
    {
        $type = $provider instanceof PushProviderType
            ? $provider
            : PushProviderType::tryFrom($provider);

        return match ($type) {
            PushProviderType::Fcm => $this->fcm,
            default => throw new InvalidArgumentException(
                'Unsupported push provider ['.($provider instanceof PushProviderType ? $provider->value : $provider).'].'
            ),
        };
    }
}
