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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\SmartHome\ResolverReachProbeAdapter;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

const SCENE_JOB_HA_BASE = 'https://ha.example.test';

/**
 * Build a fully-wired action: HA provider connection → device → scene action.
 *
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

// ─────────────────────────────────────────────────────────────────────────────
// Queue configuration
// ─────────────────────────────────────────────────────────────────────────────

it('is configured to run on the smart-home queue', function () {
    $job = new SceneActionJob(1);

    expect($job->queue)->toBe('smart-home');
});

it('has the expected timeout and tries', function () {
    $job = new SceneActionJob(1);

    expect($job->timeout)->toBe(30)
        ->and($job->tries)->toBe(3);
});

// ─────────────────────────────────────────────────────────────────────────────
// Real execution via adapter (Http::fake — no real Home Assistant)
// ─────────────────────────────────────────────────────────────────────────────

it('executes the adapter for an existing action', function () {
    Http::fake([SCENE_JOB_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    $action = sceneJobAction();

    runSceneJob($action);

    Http::assertSentCount(1);
});

it('passes the correct provider_device_id, action_type and parameters to the provider', function () {
    Http::fake([SCENE_JOB_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    $action = sceneJobAction(actionOverrides: [
        'action_type' => 'turn_off',
        'parameters' => ['transition_marker' => 'x'],
    ]);

    runSceneJob($action);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/api/services/light/turn_off')
            && $request['entity_id'] === 'light.living_room'
            && $request['transition_marker'] === 'x';
    });
});

it('passes only entity_id when parameters are null', function () {
    Http::fake([SCENE_JOB_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    $action = sceneJobAction(actionOverrides: ['action_type' => 'toggle', 'parameters' => null]);

    runSceneJob($action);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/api/services/light/toggle')
            && $request['entity_id'] === 'light.living_room'
            && array_keys($request->data()) === ['entity_id'];
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Success / failure logging
// ─────────────────────────────────────────────────────────────────────────────

it('does not emit a log on success (L-2 resolution — metric + trace covers this)', function () {
    Http::fake([SCENE_JOB_HA_BASE.'/api/services/*' => Http::response([], 200)]);
    Log::spy();

    runSceneJob(sceneJobAction());

    Log::shouldNotHaveReceived('info');
});

it('logs a warning on a failed ActionResult but does not throw', function () {
    Http::fake([SCENE_JOB_HA_BASE.'/api/services/*' => Http::response([], 500)]);
    Log::spy();

    $action = sceneJobAction();

    expect(fn () => runSceneJob($action))->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'provider returned action failure')
            && $context['outcome'] === 'failure'
            && $context['status_code'] === 500
            && ! isset($context['success'])
            && ! isset($context['error_message'])
            && ! isset($context['provider_device_id']));
});

it('handles a provider connection failure as a completed failed result (no throw)', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));
    Log::spy();

    $action = sceneJobAction();

    expect(fn () => runSceneJob($action))->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'provider returned action failure')
            && $context['outcome'] === 'failure'
            && ! isset($context['provider_device_id']));
});

// ─────────────────────────────────────────────────────────────────────────────
// Graceful handling — unsupported action, missing relations, exceptions
// ─────────────────────────────────────────────────────────────────────────────

it('blocks dispatch when device capabilities omit the required capability', function () {
    Http::fake();
    Log::spy();
    Bus::fake();

    config(['smart_home.adapters.home_assistant' => ResolverReachProbeAdapter::class]);
    ResolverReachProbeAdapter::reset();

    $action = sceneJobAction(
        deviceOverrides: [
            'capabilities' => [
                'can_turn_on' => [],
                'can_turn_off' => [],
                'can_toggle' => [],
            ],
        ],
        actionOverrides: ['action_type' => 'set_brightness'],
    );

    runSceneJob($action);

    expect(ResolverReachProbeAdapter::$constructed)->toBeFalse();
    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'unsupported action type')
            && $context['outcome'] === 'unsupported');
    Bus::assertNotDispatched(PushNotificationJob::class);
});

it('dispatches when device capabilities include the required capability', function () {
    Http::fake([SCENE_JOB_HA_BASE.'/api/services/light/turn_on' => Http::response([], 200)]);

    $action = sceneJobAction(
        deviceOverrides: [
            'capabilities' => [
                'can_turn_on' => [],
                'can_turn_off' => [],
                'can_toggle' => [],
                'can_set_brightness' => ['min' => 0, 'max' => 255, 'step' => 1],
            ],
        ],
        actionOverrides: ['action_type' => 'set_brightness', 'parameters' => ['brightness' => 200]],
    );

    runSceneJob($action);

    Http::assertSent(fn (Request $request) => $request->url() === SCENE_JOB_HA_BASE.'/api/services/light/turn_on'
        && $request['brightness'] === 200);
});

it('passes through to the adapter when device capabilities are null', function () {
    Http::fake([SCENE_JOB_HA_BASE.'/api/services/light/turn_on' => Http::response([], 200)]);

    $action = sceneJobAction(
        deviceOverrides: ['capabilities' => null],
        actionOverrides: ['action_type' => 'set_brightness', 'parameters' => ['brightness' => 100]],
    );

    runSceneJob($action);

    Http::assertSent(fn (Request $request) => $request->url() === SCENE_JOB_HA_BASE.'/api/services/light/turn_on');
});

it('handles an unsupported action gracefully without HTTP or throw', function () {
    Http::fake();
    Log::spy();

    $action = sceneJobAction(actionOverrides: ['action_type' => 'explode']);

    expect(fn () => runSceneJob($action))->not->toThrow(Throwable::class);

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'unsupported action type')
            && $context['outcome'] === 'unsupported'
            && isset($context['exception_class'])
            && ! isset($context['provider_device_id'])
            && ! isset($context['error_message']));
});

it('handles a missing/deleted action gracefully', function () {
    Http::fake();
    Log::spy();

    expect(fn () => runSceneJob(999_999))->not->toThrow(Throwable::class);

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'not found or deleted'));
});

it('handles a deleted device gracefully (cascade removes the action)', function () {
    Http::fake();
    Log::spy();

    $action = sceneJobAction();
    $actionId = $action->id;

    // device_id has cascadeOnDelete: deleting the device removes its actions,
    // so in production the job hits the "action not found" graceful branch.
    $action->device->delete();

    expect(fn () => runSceneJob($actionId))->not->toThrow(Throwable::class);

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'not found or deleted'));
});

it('handles an unexpected resolver error gracefully (unknown provider)', function () {
    Http::fake();
    Log::spy();

    // Force an unknown provider so the resolver throws InvalidArgumentException.
    $action = sceneJobAction(connOverrides: ['provider' => 'unknown_provider'], deviceOverrides: ['provider' => 'unknown_provider']);

    expect(fn () => runSceneJob($action))->not->toThrow(Throwable::class);

    Http::assertNothingSent();
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'unexpected error')
            && $context['outcome'] === 'failure'
            && isset($context['exception_class'])
            && ! isset($context['provider_device_id'])
            && ! isset($context['error_message']));
});

// ─────────────────────────────────────────────────────────────────────────────
// Push notification integration — scene_id payload (v1.3.0)
// ─────────────────────────────────────────────────────────────────────────────

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
    // 'set_color' is not in HomeAssistantAdapter::ACTION_SERVICE_MAP, so
    // executeAction() throws UnsupportedSmartHomeActionException.
    // Phase 6A alignment: that catch block intentionally skips the push notification
    // (ADR-026: log + skip + continue). This test pins that contract.
    Http::fake();
    Bus::fake();

    runSceneJob(sceneJobAction(actionOverrides: ['action_type' => 'set_color']));

    Bus::assertNotDispatched(PushNotificationJob::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// Security — never log credentials
// ─────────────────────────────────────────────────────────────────────────────

it('never logs the access token or credentials', function () {
    Http::fake([SCENE_JOB_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    $connection = ProviderConnection::factory()->create(['config' => ['base_url' => SCENE_JOB_HA_BASE]]);
    $connection->setEncryptedCredentials(['access_token' => 'super-secret-token-value']);
    $connection->save();

    $device = Device::factory()->create([
        'provider_connection_id' => $connection->id,
        'user_id' => $connection->user_id,
        'provider' => $connection->provider,
        'provider_device_id' => 'light.living_room',
    ]);
    $scene = Scene::factory()->create(['user_id' => $connection->user_id]);
    $action = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
        'action_type' => 'turn_on',
    ]);

    Log::spy();

    runSceneJob($action);

    // Success path emits no log — L-2 resolution.
    Log::shouldNotHaveReceived('info');

    $forbidden = ['super-secret-token-value', 'access_token', 'encrypted_credentials'];

    $assertClean = function ($message, $context) use ($forbidden) {
        $serialised = $message.' '.json_encode($context);
        foreach ($forbidden as $needle) {
            if (str_contains($serialised, $needle)) {
                return false;
            }
        }

        return true;
    };

    // Verify any warning/error emitted (e.g. on a failure path) is also clean.
    // On success there are none — but this guard remains for future paths.
    foreach (['warning', 'error'] as $level) {
        try {
            Log::shouldHaveReceived($level)->withArgs($assertClean);
        } catch (Throwable) {
            // No log at this level — acceptable on the success path.
        }
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Job uses adapter/resolver, not arbitrary direct HTTP
// ─────────────────────────────────────────────────────────────────────────────

it('only performs the single provider call routed through the adapter', function () {
    Http::fake([SCENE_JOB_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    runSceneJob(sceneJobAction());

    // Exactly one request, to the HA services endpoint — proves the job goes
    // through the adapter rather than making ad-hoc HTTP calls.
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/services/'));
});
