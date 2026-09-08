<?php

declare(strict_types=1);

use App\Jobs\SmartHome\SceneActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\SceneActionExecution;
use App\SmartHome\Adapters\FakeProviderAdapter;
use App\SmartHome\Services\SceneExecutionAggregationService;
use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const MULTI_HA_BASE = 'https://ha.multi-provider.test';

/**
 * Register FakeProviderAdapter for tests and return a controllable singleton.
 */
function registerFakeProviderAdapter(): FakeProviderAdapter
{
    config(['smart_home.adapters.'.FakeProviderAdapter::PROVIDER_SLUG => FakeProviderAdapter::class]);
    $fake = new FakeProviderAdapter;
    app()->singleton(FakeProviderAdapter::class, fn () => $fake);

    return $fake;
}

/**
 * Build a scene with two actions on two different provider connections
 * (home_assistant + fake), owned by the same user.
 *
 * @return array{scene: Scene, haAction: SceneAction, fakeAction: SceneAction, sceneExecutionId: string}
 */
function multiProviderSceneSetup(): array
{
    registerFakeProviderAdapter();

    $haConnection = ProviderConnection::factory()->create([
        'config' => ['base_url' => MULTI_HA_BASE],
        'provider' => 'home_assistant',
    ]);
    $fakeConnection = ProviderConnection::factory()->create([
        'config' => [],
        'provider' => FakeProviderAdapter::PROVIDER_SLUG,
        'user_id' => $haConnection->user_id,
    ]);

    $haDevice = Device::factory()->create([
        'user_id' => $haConnection->user_id,
        'provider_connection_id' => $haConnection->id,
        'provider' => 'home_assistant',
        'provider_device_id' => 'light.living_room',
    ]);
    $fakeDevice = Device::factory()->create([
        'user_id' => $haConnection->user_id,
        'provider_connection_id' => $fakeConnection->id,
        'provider' => FakeProviderAdapter::PROVIDER_SLUG,
        'provider_device_id' => 'fake.light.living',
    ]);

    $scene = Scene::factory()->create(['user_id' => $haConnection->user_id]);

    $haAction = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $haDevice->id,
        'action_type' => 'turn_on',
        'sort_order' => 0,
    ]);
    $fakeAction = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $fakeDevice->id,
        'action_type' => 'turn_on',
        'sort_order' => 1,
    ]);

    return [
        'scene' => $scene,
        'haAction' => $haAction,
        'fakeAction' => $fakeAction,
        'sceneExecutionId' => (string) Str::uuid(),
    ];
}

function runMultiProviderJob(int $actionId, string $sceneExecutionId): void
{
    app()->call([new SceneActionJob($actionId, $sceneExecutionId), 'handle']);
}

test('scene with actions on two providers executes each via its own adapter under the same scene_execution_id', function () {
    Http::fake([MULTI_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    $setup = multiProviderSceneSetup();
    $executionId = $setup['sceneExecutionId'];

    runMultiProviderJob($setup['haAction']->id, $executionId);
    runMultiProviderJob($setup['fakeAction']->id, $executionId);

    // Home Assistant made exactly one HTTP call; fake adapter made none.
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/services/light/turn_on'));

    // Two execution rows, one per action, same scene_execution_id.
    $executions = SceneActionExecution::query()
        ->where('scene_execution_id', $executionId)
        ->orderBy('scene_action_id')
        ->get();

    expect($executions)->toHaveCount(2)
        ->and($executions[0]->provider)->toBe('home_assistant')
        ->and($executions[0]->outcome)->toBe(SmartHomeActionOutcome::Success->value)
        ->and($executions[1]->provider)->toBe(FakeProviderAdapter::PROVIDER_SLUG)
        ->and($executions[1]->outcome)->toBe(SmartHomeActionOutcome::Success->value);

    // byProviderBreakdown (T22) reflects both providers correctly.
    $breakdown = app(SceneExecutionAggregationService::class)
        ->byProviderBreakdown($setup['scene']->id, $executionId);

    $byProvider = collect($breakdown)->keyBy('provider');

    expect($byProvider->keys()->all())->toEqualCanonicalizing(['home_assistant', FakeProviderAdapter::PROVIDER_SLUG])
        ->and($byProvider['home_assistant']['count_success'])->toBe(1)
        ->and($byProvider['home_assistant']['count_non_success'])->toBe(0)
        ->and($byProvider[FakeProviderAdapter::PROVIDER_SLUG]['count_success'])->toBe(1)
        ->and($byProvider[FakeProviderAdapter::PROVIDER_SLUG]['count_non_success'])->toBe(0);
});

test('summarizeExecution returns partial_success when one provider succeeds and one fails', function () {
    Http::fake([MULTI_HA_BASE.'/api/services/*' => Http::response([], 500)]);

    $setup = multiProviderSceneSetup();
    $executionId = $setup['sceneExecutionId'];

    runMultiProviderJob($setup['haAction']->id, $executionId);
    runMultiProviderJob($setup['fakeAction']->id, $executionId);

    Http::assertSentCount(1);

    $summary = app(SceneExecutionAggregationService::class)
        ->summarizeExecution($setup['scene']->id, $executionId);

    expect($summary)->not->toBeNull()
        ->and($summary['state']->value)->toBe('partial_success')
        ->and($summary['count_success'])->toBe(1)
        ->and($summary['count_non_success'])->toBe(1);

    $byProvider = collect($summary['by_provider'])->keyBy('provider');
    expect($byProvider['home_assistant']['count_non_success'])->toBe(1)
        ->and($byProvider[FakeProviderAdapter::PROVIDER_SLUG]['count_success'])->toBe(1);
});
