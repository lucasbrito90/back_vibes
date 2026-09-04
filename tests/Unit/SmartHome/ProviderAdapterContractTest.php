<?php

declare(strict_types=1);

use App\Models\ProviderConnection;
use App\SmartHome\Adapters\FakeProviderAdapter;
use App\SmartHome\Adapters\HomeAssistantAdapter;
use App\SmartHome\Contracts\ProviderAdapter;
use App\SmartHome\DeviceStatus;
use App\SmartHome\DTOs\ActionResult;
use App\SmartHome\DTOs\ConnectionHealth;
use App\SmartHome\DTOs\DeviceStatusResult;
use App\SmartHome\DTOs\ProviderDevice;
use App\SmartHome\Exceptions\ProviderConnectionException;
use App\SmartHome\Exceptions\UnsupportedSmartHomeActionException;
use App\SmartHome\ProviderAdapterRegistry;
use App\SmartHome\ProviderType;
use App\Telemetry\SmartHome\SmartHomeProviderTelemetry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Contract helpers — assertions derived from ProviderAdapter docblock only
// ─────────────────────────────────────────────────────────────────────────────

const CONTRACT_HA_BASE = 'https://ha.contract.test';
const CONTRACT_HA_TOKEN = 'contract-test-token';

function contractFakeConnection(): ProviderConnection
{
    $connection = new ProviderConnection;
    $connection->name = 'Fake Test';
    $connection->provider = FakeProviderAdapter::PROVIDER_SLUG;
    $connection->config = [];
    $connection->setEncryptedCredentials(['access_token' => 'fake-token']);

    return $connection;
}

function contractHaConnection(): ProviderConnection
{
    $connection = new ProviderConnection;
    $connection->name = 'HA Contract';
    $connection->provider = ProviderType::HomeAssistant->value;
    $connection->config = ['base_url' => CONTRACT_HA_BASE];
    $connection->setEncryptedCredentials(['access_token' => CONTRACT_HA_TOKEN]);

    return $connection;
}

function contractFakeAdapter(): FakeProviderAdapter
{
    return new FakeProviderAdapter;
}

function contractHaAdapter(): HomeAssistantAdapter
{
    return new HomeAssistantAdapter(app(SmartHomeProviderTelemetry::class));
}

/**
 * @return array<string, array{0: string, 1: callable(): ProviderAdapter, 2: callable(): ProviderConnection}>
 */
function contractAdapterFixtures(): array
{
    return [
        'fake' => [
            'fake',
            fn (): FakeProviderAdapter => contractFakeAdapter(),
            fn (): ProviderConnection => contractFakeConnection(),
        ],
        'home_assistant' => [
            'home_assistant',
            fn (): HomeAssistantAdapter => contractHaAdapter(),
            fn (): ProviderConnection => contractHaConnection(),
        ],
    ];
}

function assertProviderAdapterInterfaceMethods(ProviderAdapter $adapter): void
{
    $interface = new ReflectionClass(ProviderAdapter::class);

    foreach ($interface->getMethods() as $method) {
        expect(method_exists($adapter, $method->getName()))->toBeTrue(
            'Adapter must implement '.$method->getName().'().'
        );

        $impl = new ReflectionMethod($adapter, $method->getName());

        expect($impl->getNumberOfParameters())->toBe($method->getNumberOfParameters())
            ->and($impl->getReturnType()?->getName())->toBe($method->getReturnType()?->getName());
    }
}

function configureListDevicesUnreachable(string $kind, ProviderAdapter $adapter): void
{
    if ($kind === 'fake') {
        assert($adapter instanceof FakeProviderAdapter);
        $adapter->simulateListDevicesUnreachable();

        return;
    }

    Http::fake(fn (Request $request) => throw new ConnectionException('Connection timed out'));
}

function configureListDevicesBadStatus(string $kind, ProviderAdapter $adapter): void
{
    if ($kind === 'fake') {
        assert($adapter instanceof FakeProviderAdapter);
        $adapter->simulateListDevicesBadStatus();

        return;
    }

    Http::fake([CONTRACT_HA_BASE.'/api/states' => Http::response(['message' => 'error'], 503)]);
}

function configureReadStatusFailure(string $kind, ProviderAdapter $adapter, string $deviceId): void
{
    if ($kind === 'fake') {
        assert($adapter instanceof FakeProviderAdapter);
        $adapter->simulateReadStatusFailure();

        return;
    }

    Http::fake([CONTRACT_HA_BASE."/api/states/{$deviceId}" => Http::response(['message' => 'not found'], 404)]);
}

function configureExecuteActionTransportFailure(string $kind, ProviderAdapter $adapter, string $deviceId): void
{
    if ($kind === 'fake') {
        assert($adapter instanceof FakeProviderAdapter);
        $adapter->simulateExecuteActionTransportFailure();

        return;
    }

    Http::fake(fn (Request $request) => throw new ConnectionException('Connection timed out'));
}

function configureExecuteActionHttpFailure(string $kind, ProviderAdapter $adapter, string $deviceId): void
{
    if ($kind === 'fake') {
        assert($adapter instanceof FakeProviderAdapter);
        $adapter->simulateExecuteActionHttpFailure();

        return;
    }

    Http::fake([
        CONTRACT_HA_BASE.'/api/services/*/*' => Http::response(['message' => 'error'], 500),
    ]);
}

function configureTestConnectionUnreachable(string $kind, ProviderAdapter $adapter): void
{
    if ($kind === 'fake') {
        assert($adapter instanceof FakeProviderAdapter);
        $adapter->simulateTestConnectionUnreachable();

        return;
    }

    Http::fake(fn (Request $request) => throw new ConnectionException('Connection timed out'));
}

function configureTestConnectionBadStatus(string $kind, ProviderAdapter $adapter): void
{
    if ($kind === 'fake') {
        assert($adapter instanceof FakeProviderAdapter);
        $adapter->simulateTestConnectionBadStatus();

        return;
    }

    Http::fake([CONTRACT_HA_BASE.'/api/' => Http::response(['message' => 'Unauthorized'], 401)]);
}

function configureListDevicesSuccess(string $kind, ProviderAdapter $adapter): void
{
    if ($kind === 'home_assistant') {
        Http::fake([
            CONTRACT_HA_BASE.'/api/states' => Http::response([
                [
                    'entity_id' => 'light.contract_room',
                    'state' => 'on',
                    'attributes' => ['friendly_name' => 'Contract Light'],
                    'last_changed' => '2026-01-01T12:00:00+00:00',
                ],
            ], 200),
        ]);
    }
}

function contractDeviceId(string $kind): string
{
    return $kind === 'fake' ? 'fake.light.living' : 'light.contract_room';
}

/**
 * Adapter that deliberately violates the readStatus() never-throws policy.
 * Used only to prove the contract suite detects non-conforming implementations.
 */
final class ContractViolatingReadStatusAdapter implements ProviderAdapter
{
    public function listDevices(ProviderConnection $connection): array
    {
        return [];
    }

    public function readStatus(ProviderConnection $connection, string $deviceId): DeviceStatusResult
    {
        throw new RuntimeException('Contract violation: readStatus must never throw.');
    }

    public function executeAction(
        ProviderConnection $connection,
        string $deviceId,
        string $action,
        array $parameters = []
    ): ActionResult {
        throw UnsupportedSmartHomeActionException::forAction($action);
    }

    public function testConnection(ProviderConnection $connection): ConnectionHealth
    {
        return new ConnectionHealth(reachable: true, status_code: 200, latency_ms: 0, error_message: null);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Fake registration guard (ADR-032 — never in production config)
// ─────────────────────────────────────────────────────────────────────────────

test('fake slug is absent from committed smart_home adapters config', function () {
    $adapters = config('smart_home.adapters', []);

    expect($adapters)->not->toHaveKey('fake')
        ->and(collect($adapters)->values())->not->toContain(FakeProviderAdapter::class);
});

test('fake adapter is not resolvable via registry without runtime test registration', function () {
    expect(fn () => app(ProviderAdapterRegistry::class)->forSlug('fake'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported smart home provider [fake].');
});

test('fake adapter resolves when registered via config override and container binding only', function () {
    config(['smart_home.adapters.fake' => FakeProviderAdapter::class]);
    $this->app->singleton(FakeProviderAdapter::class, fn () => new FakeProviderAdapter);

    $adapter = app(ProviderAdapterRegistry::class)->forSlug('fake');

    expect($adapter)->toBeInstanceOf(FakeProviderAdapter::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// Contract suite — same assertions for every adapter (ProviderAdapter docblock)
// ─────────────────────────────────────────────────────────────────────────────

dataset('contract adapters', fn (): array => contractAdapterFixtures());

test('implements ProviderAdapter methods with matching signatures', function (string $kind, callable $makeAdapter, callable $makeConnection) {
    assertProviderAdapterInterfaceMethods($makeAdapter());
})->with('contract adapters');

test('listDevices throws ProviderConnectionException when provider is unreachable', function (string $kind, callable $makeAdapter, callable $makeConnection) {
    $adapter = $makeAdapter();
    configureListDevicesUnreachable($kind, $adapter);

    expect(fn () => $adapter->listDevices($makeConnection()))
        ->toThrow(ProviderConnectionException::class);
})->with('contract adapters');

test('listDevices throws ProviderConnectionException on non-2xx provider response', function (string $kind, callable $makeAdapter, callable $makeConnection) {
    $adapter = $makeAdapter();
    configureListDevicesBadStatus($kind, $adapter);

    expect(fn () => $adapter->listDevices($makeConnection()))
        ->toThrow(ProviderConnectionException::class);
})->with('contract adapters');

test('readStatus never throws and returns DeviceStatus Unknown on failure', function (string $kind, callable $makeAdapter, callable $makeConnection) {
    $adapter = $makeAdapter();
    $deviceId = contractDeviceId($kind);
    configureReadStatusFailure($kind, $adapter, $deviceId);

    $result = $adapter->readStatus($makeConnection(), $deviceId);

    expect($result)->toBeInstanceOf(DeviceStatusResult::class)
        ->and($result->status)->toBe(DeviceStatus::Unknown);
})->with('contract adapters');

test('executeAction never throws for transport failure and returns failed ActionResult', function (string $kind, callable $makeAdapter, callable $makeConnection) {
    $adapter = $makeAdapter();
    $deviceId = contractDeviceId($kind);
    configureExecuteActionTransportFailure($kind, $adapter, $deviceId);

    $result = $adapter->executeAction($makeConnection(), $deviceId, 'turn_on');

    expect($result)->toBeInstanceOf(ActionResult::class)
        ->and($result->success)->toBeFalse()
        ->and($result->status_code)->toBeNull();
})->with('contract adapters');

test('executeAction never throws for HTTP failure and returns failed ActionResult', function (string $kind, callable $makeAdapter, callable $makeConnection) {
    $adapter = $makeAdapter();
    $deviceId = contractDeviceId($kind);
    configureExecuteActionHttpFailure($kind, $adapter, $deviceId);

    $result = $adapter->executeAction($makeConnection(), $deviceId, 'turn_on');

    expect($result)->toBeInstanceOf(ActionResult::class)
        ->and($result->success)->toBeFalse()
        ->and($result->status_code)->not->toBeNull();
})->with('contract adapters');

test('executeAction throws UnsupportedSmartHomeActionException for unmappable actions', function (string $kind, callable $makeAdapter, callable $makeConnection) {
    $adapter = $makeAdapter();
    $deviceId = contractDeviceId($kind);

    expect(fn () => $adapter->executeAction($makeConnection(), $deviceId, 'activate_scene'))
        ->toThrow(UnsupportedSmartHomeActionException::class);
})->with('contract adapters');

test('testConnection never throws and returns ConnectionHealth with reachable false on failure', function (string $kind, callable $makeAdapter, callable $makeConnection) {
    $adapter = $makeAdapter();
    configureTestConnectionUnreachable($kind, $adapter);

    $health = $adapter->testConnection($makeConnection());

    expect($health)->toBeInstanceOf(ConnectionHealth::class)
        ->and($health->reachable)->toBeFalse();
})->with('contract adapters');

test('testConnection never throws and returns ConnectionHealth with reachable false on non-2xx', function (string $kind, callable $makeAdapter, callable $makeConnection) {
    $adapter = $makeAdapter();
    configureTestConnectionBadStatus($kind, $adapter);

    $health = $adapter->testConnection($makeConnection());

    expect($health)->toBeInstanceOf(ConnectionHealth::class)
        ->and($health->reachable)->toBeFalse()
        ->and($health->status_code)->not->toBeNull();
})->with('contract adapters');

test('listDevices returns ProviderDevice entries with ADR-033 capabilities map when derivable', function (string $kind, callable $makeAdapter, callable $makeConnection) {
    $adapter = $makeAdapter();
    configureListDevicesSuccess($kind, $adapter);

    $devices = $adapter->listDevices($makeConnection());

    expect($devices)->not->toBeEmpty();

    $device = $devices[0];
    expect($device)->toBeInstanceOf(ProviderDevice::class)
        ->and($device->capabilities)->toBeArray()
        ->and($device->capabilities)->not->toBeNull()
        ->and(array_is_list($device->capabilities))->toBeFalse()
        ->and($device->capabilities)->toHaveKey('can_turn_on');
})->with('contract adapters');

test('FakeProviderAdapter default catalog includes can_set_brightness with range constraints', function () {
    $devices = contractFakeAdapter()->listDevices(contractFakeConnection());
    $light = collect($devices)->firstWhere('provider_device_id', 'fake.light.living');

    expect($light)->not->toBeNull()
        ->and($light->capabilities['can_set_brightness'])->toMatchArray([
            'min' => 0,
            'max' => 255,
            'step' => 1,
        ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Contract suite self-check — must detect a violating adapter
// ─────────────────────────────────────────────────────────────────────────────

test('contract readStatus policy detects an adapter that throws instead of returning Unknown', function () {
    $violator = new ContractViolatingReadStatusAdapter;

    expect(fn () => $violator->readStatus(contractFakeConnection(), 'any.device'))
        ->toThrow(RuntimeException::class, 'Contract violation: readStatus must never throw.');
});
