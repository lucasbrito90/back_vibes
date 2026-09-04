<?php

declare(strict_types=1);

namespace App\SmartHome;

use App\SmartHome\DTOs\ProviderDescriptor;
use InvalidArgumentException;

/**
 * Exposes provider descriptors for slugs registered in ProviderAdapterRegistry.
 *
 * A slug present in the adapter map but missing from provider_descriptors config
 * is a boot-time configuration error.
 */
final class ProviderDescriptorRegistry
{
    public function __construct(
        private readonly ProviderAdapterRegistry $adapterRegistry,
    ) {}

    /**
     * @return list<ProviderDescriptor>
     */
    public function all(): array
    {
        $descriptors = [];

        foreach ($this->adapterRegistry->registeredSlugs() as $slug) {
            $descriptors[] = $this->forSlug($slug);
        }

        return $descriptors;
    }

    public function forSlug(string $slug): ProviderDescriptor
    {
        if (! in_array($slug, $this->adapterRegistry->registeredSlugs(), true)) {
            throw new InvalidArgumentException(
                'No descriptor for unregistered smart home provider ['.$slug.'].'
            );
        }

        /** @var array<string, mixed>|null $config */
        $config = config('smart_home.provider_descriptors.'.$slug);

        if (! is_array($config)) {
            throw new InvalidArgumentException(
                'Missing provider descriptor config for registered slug ['.$slug.'].'
            );
        }

        return ProviderDescriptor::fromConfigArray($slug, $config);
    }
}
