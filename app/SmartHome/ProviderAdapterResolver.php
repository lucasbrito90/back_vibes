<?php

declare(strict_types=1);

namespace App\SmartHome;

use App\SmartHome\Adapters\HomeAssistantAdapter;
use App\SmartHome\Contracts\ProviderAdapter;
use InvalidArgumentException;

/**
 * Resolves the correct ProviderAdapter for a given provider.
 *
 * Used by the device sync / action execution layers so callers depend on the
 * ProviderAdapter contract, not a concrete adapter (ADR-012). New providers are
 * registered here only.
 */
final class ProviderAdapterResolver
{
    public function __construct(
        private readonly HomeAssistantAdapter $homeAssistant,
    ) {}

    /**
     * @throws InvalidArgumentException When the provider is unknown or unsupported.
     */
    public function forProvider(ProviderType|string $provider): ProviderAdapter
    {
        $type = $provider instanceof ProviderType
            ? $provider
            : ProviderType::tryFrom($provider);

        return match ($type) {
            ProviderType::HomeAssistant => $this->homeAssistant,
            default => throw new InvalidArgumentException(
                'Unsupported smart home provider ['.($provider instanceof ProviderType ? $provider->value : $provider).'].'
            ),
        };
    }
}
