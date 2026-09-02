<?php

declare(strict_types=1);

use App\Jobs\PushNotifications\PushNotificationJob;
use App\Jobs\SmartHome\SceneActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\PushNotifications\Services\PushNotificationEvents;
use App\SmartHome\ProviderAdapterResolver;
use App\Telemetry\SmartHome\SmartHomeActionTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const SCENE_JOB_HA_BASE = 'https://ha.example.test';

/**
 * @param  array<string, mixed>  $connOverrides
 * @param  array<string, mixed>  $deviceOverrides
 * @param  array<string, mixed>  $actionOverrides
 */
function sceneJobAction(array $connOverrides = [], array $deviceOverrides = [], array $actionOverrides = []): SceneAction
{
    $connection = ProviderConnection::factory()->create(array_merge([
        'config' => ['base_url' => SCENE_JOB_HA_BASE],
    ], $connOverrides));

    $device = Device::factory()->create(array_merge([
        'provider_connection_id' => $connection->id,
        'user_id' => $connection->user_id,
        'provider' => $connection->provider,
        'provider_device_id' => 'light.living_room',
    ], $deviceOverrides));

    $scene = Scene::factory()->create(['user_id' => $connection->user_id]);

    return SceneAction::factory()->create(array_merge([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
        'action_type' => 'turn_on',
        'parameters' => null,
    ], $actionOverrides));
}

function runSceneJob(SceneAction|int $action): void
{
    $id = $action instanceof SceneAction ? $action->id : $action;
    (new SceneActionJob($id))->handle(
        app(ProviderAdapterResolver::class),
        app(PushNotificationEvents::class),
        app(SmartHomeActionTelemetry::class),
    );
}

it('notifies the owner via PushNotificationEvents on a failed action result with scene_id', function () {
    Http::fake([SCENE_JOB_HA_BASE.'/api/services/*' => Http::response([], 500)]);
    Bus::fake();

    $action = sceneJobAction();

    runSceneJob($action);

    Bus::assertDispatched(PushNotificationJob::class, function (PushNotificationJob $job) use ($action) {
        return $job->payload->data['type'] === 'smart_home_scene_action_failed'
            && $job->payload->data['device_id'] === (string) $action->device_id
            && $job->payload->data['scene_id'] === (string) $action->scene_id
            && ! array_key_exists('vibe_id', $job->payload->data);
    });
});

it('notifies the owner via PushNotificationEvents on an unexpected error with scene_id', function () {
    Http::fake();
    Bus::fake();

    $action = sceneJobAction(
        connOverrides: ['provider' => 'unknown_provider'],
        deviceOverrides: ['provider' => 'unknown_provider'],
    );

    runSceneJob($action);

    Bus::assertDispatched(PushNotificationJob::class, function (PushNotificationJob $job) use ($action) {
        return $job->payload->data['type'] === 'smart_home_scene_action_failed'
            && $job->payload->data['scene_id'] === (string) $action->scene_id;
    });
});

it('does not notify via PushNotificationEvents on a successful scene action', function () {
    Http::fake([SCENE_JOB_HA_BASE.'/api/services/*' => Http::response([], 200)]);
    Bus::fake();

    runSceneJob(sceneJobAction());

    Bus::assertNotDispatched(PushNotificationJob::class);
});

it('does not notify via PushNotificationEvents for an unsupported scene action type', function () {
    Http::fake();
    Bus::fake();

    runSceneJob(sceneJobAction(actionOverrides: ['action_type' => 'set_brightness']));

    Bus::assertNotDispatched(PushNotificationJob::class);
});
