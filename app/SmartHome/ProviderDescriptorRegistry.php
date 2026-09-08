<?php

declare(strict_types=1);

namespace App\SmartHome;

use App\SmartHome\DTOs\ProviderDescriptor;
use InvalidArgumentException;

/**
 * Exposes provider descriptors for slugs registered as known providers
 * (config('smart_home.known_providers') — ADR-036 Decision 3).
 *
 * Deliberately independent of ProviderAdapterRegistry: identity/metadata is
 * a superset of server-side adapter registration, not derived from it. A
 * known provider (e.g. google_home) may have no ProviderAdapter at all.
 *
 * A slug present in known_providers but missing from provider_descriptors
 * config is a boot-time configuration error.
 */
final class ProviderDescriptorRegistry
{
    /**
     * @return list<ProviderDescriptor>
     */
    public function all(): array
    {
        $descriptors = [];

        foreach ($this->knownSlugs() as $slug) {
            $descriptors[] = $this->forSlug($slug);
        }

        return $descriptors;
    }

    public function forSlug(string $slug): ProviderDescriptor
    {
        if (! in_array($slug, $this->knownSlugs(), true)) {
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

    /**
     * @return list<string>
     */
    private function knownSlugs(): array
    {
        /** @var mixed $slugs */
        $slugs = config('smart_home.known_providers', []);

        return is_array($slugs) ? array_values($slugs) : [];
    }
}
