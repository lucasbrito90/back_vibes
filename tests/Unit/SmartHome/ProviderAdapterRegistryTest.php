<?php

declare(strict_types=1);

use App\Models\ProviderConnection;
use App\SmartHome\Adapters\HomeAssistantAdapter;
use App\SmartHome\Contracts\ProviderAdapter;
use App\SmartHome\DTOs\ActionResult;
use App\SmartHome\DTOs\ConnectionHealth;
use App\SmartHome\DTOs\DeviceStatusResult;
use App\SmartHome\ProviderAdapterRegistry;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Minimal ProviderAdapter stub for registry tests (T08).
 */
final class RegistryStubAdapter implements ProviderAdapter
{
    public function listDevices(ProviderConnection $connection): array
    {
        return [];
    }

    public function readStatus(ProviderConnection $connection, string $deviceId): DeviceStatusResult
    {
        throw new RuntimeException('Not implemented in stub.');
    }

    public function executeAction(
        ProviderConnection $connection,
        string $deviceId,
        string $action,
        array $parameters = []
    ): ActionResult {
        throw new RuntimeException('Not implemented in stub.');
    }

    public function testConnection(ProviderConnection $connection): ConnectionHealth
    {
        return new ConnectionHealth(reachable: true, status_code: 200, latency_ms: 0, error_message: null);
    }
}

test('registeredSlugs returns home_assistant sorted in the default config state', function () {
    expect(app(ProviderAdapterRegistry::class)->registeredSlugs())->toBe(['home_assistant']);
});

test('forSlug resolves home_assistant to HomeAssistantAdapter from the container', function () {
    $adapter = app(ProviderAdapterRegistry::class)->forSlug('home_assistant');

    expect($adapter)->toBeInstanceOf(HomeAssistantAdapter::class)
        ->and($adapter)->toBeInstanceOf(ProviderAdapter::class);
});

test('forSlug throws InvalidArgumentException for an unknown slug with a clear message', function () {
    expect(fn () => app(ProviderAdapterRegistry::class)->forSlug('unknown_slug'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported smart home provider [unknown_slug].');
});

test('forSlug throws InvalidArgumentException when config maps slug to a non-adapter class', function () {
    config(['smart_home.adapters.bad' => stdClass::class]);

    expect(fn () => app(ProviderAdapterRegistry::class)->forSlug('bad'))
        ->toThrow(InvalidArgumentException::class, 'must implement ProviderAdapter');
});

test('forSlug resolves an adapter added via runtime config override without editing registry source', function () {
    config(['smart_home.adapters.registry_stub' => RegistryStubAdapter::class]);
    $this->app->singleton(RegistryStubAdapter::class);

    $adapter = app(ProviderAdapterRegistry::class)->forSlug('registry_stub');

    expect($adapter)->toBeInstanceOf(RegistryStubAdapter::class);
});

test('registeredSlugs reflects adapters added via runtime config override', function () {
    config([
        'smart_home.adapters' => [
            'home_assistant' => HomeAssistantAdapter::class,
            'registry_stub' => RegistryStubAdapter::class,
        ],
    ]);

    expect(app(ProviderAdapterRegistry::class)->registeredSlugs())
        ->toBe(['home_assistant', 'registry_stub']);
});

test('ProviderAdapterRegistry is bound as a singleton in the container', function () {
    expect(app(ProviderAdapterRegistry::class))->toBe(app(ProviderAdapterRegistry::class));
});
