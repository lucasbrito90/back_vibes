<?php

declare(strict_types=1);

namespace App\SmartHome;

use App\SmartHome\Contracts\ProviderAdapter;
use InvalidArgumentException;

/**
 * Resolves the correct ProviderAdapter for a given provider.
 *
 * Used by the device sync / action execution layers so callers depend on the
 * ProviderAdapter contract, not a concrete adapter (ADR-012). Delegates slug
 * lookup to ProviderAdapterRegistry (ADR-032 decision B).
 */
final class ProviderAdapterResolver
{
    public function __construct(
        private readonly ProviderAdapterRegistry $registry,
    ) {}

    /**
     * @throws InvalidArgumentException When the provider is unknown or unsupported.
     */
    public function forProvider(ProviderType|string $provider): ProviderAdapter
    {
        $slug = $provider instanceof ProviderType
            ? $provider->value
            : $provider;

        return $this->registry->forSlug($slug);
    }
}
