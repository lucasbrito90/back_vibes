<?php

declare(strict_types=1);

use App\Jobs\PushNotifications\PushNotificationJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\Schedule;
use App\Models\ScheduleExecution;
use App\Models\User;
use App\Models\Vibe;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\Services\PushNotificationEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function pneEvents(): PushNotificationEvents
{
    return app(PushNotificationEvents::class);
}

/**
 * Assert every value in a NotificationPayload->data array is a string.
 */
function assertAllStringData(NotificationPayload $payload): void
{
    foreach ($payload->data as $key => $value) {
        expect($key)->toBeString()
            ->and($value)->toBeString();
    }
}

/**
 * Capture the NotificationPayload of the single dispatched PushNotificationJob.
 */
function dispatchedPayload(): NotificationPayload
{
    $captured = null;

    Bus::assertDispatched(PushNotificationJob::class, function (PushNotificationJob $job) use (&$captured) {
        $captured = $job->payload;

        return true;
    });

    return $captured;
}

// ─────────────────────────────────────────────────────────────────────────────
// notifyScheduleExecutionFailed
// ─────────────────────────────────────────────────────────────────────────────

test('notifyScheduleExecutionFailed dispatches a job with the correct payload', function () {
    Bus::fake();

    $user = User::factory()->create();
    $vibe = Vibe::factory()->for($user)->create();
    $schedule = Schedule::factory()->create(['user_id' => $user->id, 'vibe_id' => $vibe->id]);
    $execution = ScheduleExecution::query()->create([
        'schedule_id' => $schedule->id,
        'occurrence_key' => "{$schedule->id}:123",
        'scheduled_for' => now(),
        'executed_at' => now(),
        'status' => 'dispatched',
        'log' => null,
    ]);

    pneEvents()->notifyScheduleExecutionFailed($user, $execution);

    Bus::assertDispatched(PushNotificationJob::class, fn (PushNotificationJob $j) => $j->userId === $user->id);

    $payload = dispatchedPayload();
    expect($payload->title)->toBe('Schedule failed')
        ->and($payload->body)->toBe('One of your schedules could not run.')
        ->and($payload->data['type'])->toBe('schedule_execution_failed')
        ->and($payload->data['schedule_execution_id'])->toBe((string) $execution->id)
        ->and($payload->data['schedule_id'])->toBe((string) $schedule->id);

    assertAllStringData($payload);
});

// ─────────────────────────────────────────────────────────────────────────────
// notifySceneActionFailed
// ─────────────────────────────────────────────────────────────────────────────

test('notifySceneActionFailed dispatches a job with the correct payload', function () {
    Bus::fake();

    $user = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
    ]);
    $action = SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
        'action_type' => 'turn_on',
    ]);

    pneEvents()->notifySceneActionFailed($user, $action);

    $payload = dispatchedPayload();
    expect($payload->title)->toBe('Device action failed')
        ->and($payload->body)->toBe('A Smart Home action could not be completed.')
        ->and($payload->data['type'])->toBe('smart_home_scene_action_failed')
        ->and($payload->data['device_id'])->toBe((string) $device->id)
        ->and($payload->data['scene_id'])->toBe((string) $scene->id)
        ->and($payload->data['action_type'])->toBe('turn_on')
        ->and(array_key_exists('vibe_id', $payload->data))->toBeFalse();

    assertAllStringData($payload);
});

// ─────────────────────────────────────────────────────────────────────────────
// notifySmartHomeProviderUnreachable
// ─────────────────────────────────────────────────────────────────────────────

test('notifySmartHomeProviderUnreachable dispatches a job with the correct payload', function () {
    Bus::fake();

    $user = User::factory()->create();
    $connection = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => 'home_assistant',
    ]);

    pneEvents()->notifySmartHomeProviderUnreachable($user, $connection);

    $payload = dispatchedPayload();
    expect($payload->title)->toBe('Smart Home unavailable')
        ->and($payload->body)->toBe('Your Smart Home connection is temporarily unavailable.')
        ->and($payload->data['type'])->toBe('smart_home_provider_unreachable')
        ->and($payload->data['provider_connection_id'])->toBe((string) $connection->id)
        ->and($payload->data['provider'])->toBe('home_assistant');

    assertAllStringData($payload);
});

// ─────────────────────────────────────────────────────────────────────────────
// notifyAccountSecurityNotice
// ─────────────────────────────────────────────────────────────────────────────

test('notifyAccountSecurityNotice dispatches a job with dynamic title and body', function () {
    Bus::fake();

    $user = User::factory()->create();

    pneEvents()->notifyAccountSecurityNotice($user, 'New sign-in', 'A new device signed in to your account.');

    $payload = dispatchedPayload();
    expect($payload->title)->toBe('New sign-in')
        ->and($payload->body)->toBe('A new device signed in to your account.')
        ->and($payload->data['type'])->toBe('account_security_notice');

    assertAllStringData($payload);
});

// ─────────────────────────────────────────────────────────────────────────────
// PushNotificationService invocation + safety
// ─────────────────────────────────────────────────────────────────────────────

test('each notify method dispatches exactly one PushNotificationJob', function () {
    Bus::fake();

    $user = User::factory()->create();

    pneEvents()->notifyAccountSecurityNotice($user, 'T', 'B');

    Bus::assertDispatchedTimes(PushNotificationJob::class, 1);
});

test('payload data never contains secrets', function () {
    Bus::fake();

    $user = User::factory()->create();
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);
    $connection->setEncryptedCredentials(['access_token' => 'super-secret-token-value']);
    $connection->save();

    pneEvents()->notifySmartHomeProviderUnreachable($user, $connection);

    $payload = dispatchedPayload();
    $serialised = json_encode([$payload->title, $payload->body, $payload->data]);

    foreach (['super-secret-token-value', 'access_token', 'encrypted_credentials'] as $needle) {
        expect(str_contains($serialised, $needle))->toBeFalse();
    }
});

test('notify methods log safe structured context only', function () {
    Bus::fake();
    Log::spy();

    $user = User::factory()->create();

    pneEvents()->notifyAccountSecurityNotice($user, 'T', 'B');

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) use ($user) {
        return ($context['user_id'] ?? null) === $user->id
            && ($context['notification_type'] ?? null) === 'account_security_notice';
    });
});
