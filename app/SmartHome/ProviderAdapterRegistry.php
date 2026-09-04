<?php

declare(strict_types=1);

namespace App\SmartHome;

use App\SmartHome\Contracts\ProviderAdapter;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Slug → adapter class registry for Smart Home providers (ADR-032 decision B).
 *
 * Reads config('smart_home.adapters') on each call — no cached map on this
 * singleton, so tests may override config at runtime before resolving a slug.
 * Octane-safe: no per-request mutable state; forSlug() resolves from the
 * container on every call.
 */
final class ProviderAdapterRegistry
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @throws InvalidArgumentException When the slug is unknown or the class does not implement ProviderAdapter.
     */
    public function forSlug(string $slug): ProviderAdapter
    {
        $adapters = $this->adapterMap();

        if (! array_key_exists($slug, $adapters)) {
            throw new InvalidArgumentException(
                'Unsupported smart home provider ['.$slug.'].'
            );
        }

        $class = $adapters[$slug];

        if (! is_string($class) || ! is_a($class, ProviderAdapter::class, true)) {
            throw new InvalidArgumentException(
                'Smart home adapter class ['.($class ?? 'null').'] for provider ['.$slug.'] must implement ProviderAdapter.'
            );
        }

        return $this->container->make($class);
    }

    /**
     * @return list<string>
     */
    public function registeredSlugs(): array
    {
        $slugs = array_keys($this->adapterMap());
        sort($slugs);

        return $slugs;
    }

    /**
     * @return array<string, class-string<ProviderAdapter>>
     */
    private function adapterMap(): array
    {
        $adapters = config('smart_home.adapters', []);

        return is_array($adapters) ? $adapters : [];
    }
}
