<?php

declare(strict_types=1);

use App\Jobs\SmartHome\SceneActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\Vibe;
use App\SmartHome\Services\SceneDispatchService;
use App\SmartHome\Services\VibeSmartHomeDispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

test('SceneDispatchService generates scene_execution_id and passes it to each job', function () {
    Bus::fake();

    $connection = ProviderConnection::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $connection->user_id]);
    $device = Device::factory()->create([
        'user_id' => $connection->user_id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
    ]);

    $first = SceneAction::factory()->create(['scene_id' => $scene->id, 'device_id' => $device->id, 'sort_order' => 0]);
    $second = SceneAction::factory()->create(['scene_id' => $scene->id, 'device_id' => $device->id, 'sort_order' => 1]);

    $result = app(SceneDispatchService::class)->dispatch($scene);

    expect($result->scene_execution_id)->not->toBeEmpty()
        ->and($result->dispatched)->toBe(2)
        ->and($result->action_ids)->toBe([$first->id, $second->id]);

    Bus::assertDispatched(SceneActionJob::class, 2);

    $executionIds = [];

    Bus::assertDispatched(SceneActionJob::class, function (SceneActionJob $job) use (&$executionIds, $result) {
        $executionIds[] = $job->sceneExecutionId;

        return $job->sceneExecutionId === $result->scene_execution_id;
    });

    expect(array_unique($executionIds))->toHaveCount(1);
});

test('VibeSmartHomeDispatchService shares scene_execution_id across actions in one dispatch', function () {
    Bus::fake();

    $connection = ProviderConnection::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $connection->user_id]);
    $vibe = Vibe::factory()->create(['user_id' => $connection->user_id, 'scene_id' => $scene->id]);
    $device = Device::factory()->create([
        'user_id' => $connection->user_id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
    ]);

    SceneAction::factory()->create(['scene_id' => $scene->id, 'device_id' => $device->id, 'sort_order' => 0]);
    SceneAction::factory()->create(['scene_id' => $scene->id, 'device_id' => $device->id, 'sort_order' => 1]);

    $result = app(VibeSmartHomeDispatchService::class)->dispatch($vibe);

    expect($result->scene_execution_id)->not->toBeEmpty()
        ->and($result->dispatched)->toBe(2);

    $executionIds = [];

    Bus::assertDispatched(SceneActionJob::class, function (SceneActionJob $job) use (&$executionIds, $result) {
        $executionIds[] = $job->sceneExecutionId;

        return $job->sceneExecutionId === $result->scene_execution_id;
    });

    expect(array_unique($executionIds))->toHaveCount(1);
});

test('VibeSmartHomeDispatchService returns scene_execution_id even when vibe has no scene', function () {
    Bus::fake();

    $vibe = Vibe::factory()->create(['scene_id' => null]);

    $result = app(VibeSmartHomeDispatchService::class)->dispatch($vibe);

    expect($result->scene_execution_id)->not->toBeEmpty()
        ->and($result->dispatched)->toBe(0);

    Bus::assertNothingDispatched();
});
