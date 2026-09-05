<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\SceneActionExecution;
use App\Models\User;
use App\SmartHome\ActionType;
use App\SmartHome\Adapters\FakeProviderAdapter;
use App\SmartHome\DeviceType;
use App\SmartHome\SceneExecutionAggregateState;
use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use App\Telemetry\SmartHome\SmartHomeActionProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

const FAKE_CYCLE_HA_BASE = 'https://fake.cycle.test';

function fpcJwt(User $user): UnencryptedToken
{
    $dataset = new DataSet([
        'sub' => $user->firebase_uid,
        'email' => $user->email,
        'name' => $user->name,
    ], 'e30.');

    $jwt = Mockery::mock(UnencryptedToken::class);
    $jwt->shouldReceive('claims')->andReturn($dataset);

    return $jwt;
}

function fpcAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(fpcJwt($user)));
}

function fpcHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function fpcUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-fpc-'.uniqid()]);
}

/**
 * Register fake adapter + descriptor for HTTP-real provider connection flows (T10/T29).
 */
function registerFakeProviderForHttpCycle(): FakeProviderAdapter
{
    config([
        'smart_home.adapters.'.FakeProviderAdapter::PROVIDER_SLUG => FakeProviderAdapter::class,
        'smart_home.provider_descriptors.'.FakeProviderAdapter::PROVIDER_SLUG => [
            'label' => 'Fake Provider',
            'config' => [
                'base_url' => [
                    'type' => 'string',
                    'format' => 'url:https',
                    'required' => true,
                ],
            ],
            'credentials' => [
                'access_token' => [
                    'type' => 'string',
                    'required' => true,
                ],
            ],
        ],
    ]);

    $fake = new FakeProviderAdapter;
    app()->singleton(FakeProviderAdapter::class, fn () => $fake);

    return $fake;
}

/**
 * Expected default catalog from FakeProviderAdapter::defaultDevices() (T10).
 *
 * @return array<string, array<string, mixed>>
 */
function fakeProviderExpectedDevicesById(): array
{
    return [
        'fake.light.living' => [
            'name' => 'Fake Living Light',
            'type' => DeviceType::Lighting->value,
            'capabilities' => [
                'can_turn_on' => [],
                'can_turn_off' => [],
                'can_toggle' => [],
                'can_set_brightness' => ['min' => 0, 'max' => 255, 'step' => 1],
            ],
        ],
        'fake.switch.kitchen' => [
            'name' => 'Fake Kitchen Switch',
            'type' => DeviceType::Switchable->value,
            'capabilities' => [
                'can_turn_on' => [],
                'can_turn_off' => [],
                'can_toggle' => [],
            ],
        ],
    ];
}

test('fake provider completes connection sync scene execute and execution read via real HTTP API', function () {
    registerFakeProviderForHttpCycle();

    $user = fpcUser('fb-fpc-full-cycle');
    fpcAuth($user);

    // 1. Create provider connection through FormRequest + controller.
    $connectionResponse = $this->postJson('/api/provider-connections', [
        'name' => 'Fake Cycle Connection',
        'provider' => FakeProviderAdapter::PROVIDER_SLUG,
        'config' => ['base_url' => FAKE_CYCLE_HA_BASE],
        'encrypted_credentials' => ['access_token' => 'fake-cycle-token'],
    ], fpcHeaders())->assertCreated();

    $connectionId = $connectionResponse->json('data.id');
    expect($connectionResponse->json('data.provider'))->toBe(FakeProviderAdapter::PROVIDER_SLUG);

    // 2. Sync devices via HTTP — ProviderDeviceSyncService + FakeProviderAdapter.
    $this->postJson("/api/provider-connections/{$connectionId}/sync", [], fpcHeaders())
        ->assertOk();

    $devices = Device::query()
        ->where('provider_connection_id', $connectionId)
        ->orderBy('provider_device_id')
        ->get();

    expect($devices)->toHaveCount(2);

    foreach (fakeProviderExpectedDevicesById() as $providerDeviceId => $expected) {
        $device = $devices->firstWhere('provider_device_id', $providerDeviceId);

        expect($device)->not->toBeNull()
            ->and($device->name)->toBe($expected['name'])
            ->and($device->type)->toBe($expected['type'])
            ->and($device->provider)->toBe(FakeProviderAdapter::PROVIDER_SLUG)
            ->and($device->capabilities)->toMatchArray($expected['capabilities']);
    }

    $targetDevice = $devices->firstWhere('provider_device_id', 'fake.light.living');
    expect($targetDevice)->not->toBeNull();

    // 3. Scene + action via HTTP.
    $sceneResponse = $this->postJson('/api/scenes', [
        'name' => 'Fake Cycle Scene',
        'description' => 'T29 full HTTP cycle',
    ], fpcHeaders())->assertCreated();

    $sceneId = $sceneResponse->json('data.id');

    $actionResponse = $this->postJson("/api/scenes/{$sceneId}/actions", [
        'device_id' => $targetDevice->id,
        'action_type' => ActionType::TurnOn->value,
        'delay_seconds' => 0,
    ], fpcHeaders())->assertCreated();

    $actionId = $actionResponse->json('data.id');

    // 4. Execute via HTTP — QUEUE_CONNECTION=sync in phpunit.xml runs SceneActionJob inline.
    $executeResponse = $this->postJson("/api/scenes/{$sceneId}/execute", [], fpcHeaders())
        ->assertOk()
        ->assertJsonStructure(['data' => ['scene_id', 'dispatched', 'skipped', 'action_ids', 'scene_execution_id']]);

    $sceneExecutionId = $executeResponse->json('data.scene_execution_id');
    expect($sceneExecutionId)->not->toBeEmpty()
        ->and($executeResponse->json('data.dispatched'))->toBe(1)
        ->and($executeResponse->json('data.action_ids'))->toBe([$actionId]);

    // 5. Domain persistence — provider slug is the real string, not a telemetry label.
    $execution = SceneActionExecution::query()
        ->where('scene_execution_id', $sceneExecutionId)
        ->where('scene_action_id', $actionId)
        ->first();

    expect($execution)->not->toBeNull()
        ->and($execution->provider)->toBe(FakeProviderAdapter::PROVIDER_SLUG)
        ->and($execution->outcome)->toBe(SmartHomeActionOutcome::Success->value);

    // 6. Aggregate read API (T22).
    $summaryResponse = $this->getJson(
        "/api/scenes/{$sceneId}/executions/{$sceneExecutionId}",
        fpcHeaders(),
    )->assertOk();

    expect($summaryResponse->json('data.state'))->toBe(SceneExecutionAggregateState::Success->value)
        ->and($summaryResponse->json('data.count_success'))->toBe(1)
        ->and($summaryResponse->json('data.count_non_success'))->toBe(0)
        ->and($summaryResponse->json('data.count_total'))->toBe(1);

    $byProvider = collect($summaryResponse->json('data.by_provider'))->keyBy('provider');
    expect($byProvider->has(FakeProviderAdapter::PROVIDER_SLUG))->toBeTrue()
        ->and($byProvider[FakeProviderAdapter::PROVIDER_SLUG]['count_success'])->toBe(1)
        ->and($byProvider[FakeProviderAdapter::PROVIDER_SLUG]['count_non_success'])->toBe(0);

    // 7. Telemetry layer — test-only fake slug intentionally maps to Future (T24 / T28 guide).
    expect(SmartHomeActionProvider::fromProviderSlug(FakeProviderAdapter::PROVIDER_SLUG))
        ->toBe(SmartHomeActionProvider::Future);
});
