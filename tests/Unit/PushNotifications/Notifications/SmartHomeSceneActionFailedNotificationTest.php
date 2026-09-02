<?php

declare(strict_types=1);

use App\Models\SceneAction;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\Notifications\SmartHomeSceneActionFailedNotification;

// ─────────────────────────────────────────────────────────────────────────────
// SmartHomeSceneActionFailedNotification — unit tests (v1.3.0)
//
// Replaces the SmartHomeActionFailedNotification unit suite: the vibe-centric
// builder was removed once Vibe dispatch started resolving its actions from
// the linked Scene. The payload carries scene_id, never vibe_id — a Scene may
// be shared by several Vibes or executed with no Vibe context at all.
// ─────────────────────────────────────────────────────────────────────────────

function makeSceneAction(array $attributes = []): SceneAction
{
    $action = new SceneAction;
    foreach ($attributes as $key => $value) {
        $action->$key = $value;
    }

    return $action;
}

test('build returns a NotificationPayload', function () {
    $action = makeSceneAction(['device_id' => 1, 'scene_id' => 2, 'action_type' => 'turn_on']);

    expect(SmartHomeSceneActionFailedNotification::build($action))->toBeInstanceOf(NotificationPayload::class);
});

test('title is "Device action failed"', function () {
    $payload = SmartHomeSceneActionFailedNotification::build(makeSceneAction(['device_id' => 1, 'scene_id' => 2, 'action_type' => 'turn_on']));

    expect($payload->title)->toBe('Device action failed');
});

test('body is the expected string', function () {
    $payload = SmartHomeSceneActionFailedNotification::build(makeSceneAction(['device_id' => 1, 'scene_id' => 2, 'action_type' => 'turn_on']));

    expect($payload->body)->toBe('A Smart Home action could not be completed.');
});

test('data contains type=smart_home_scene_action_failed', function () {
    $payload = SmartHomeSceneActionFailedNotification::build(makeSceneAction(['device_id' => 1, 'scene_id' => 2, 'action_type' => 'turn_on']));

    expect($payload->data['type'])->toBe('smart_home_scene_action_failed');
});

test('data contains device_id as string', function () {
    $payload = SmartHomeSceneActionFailedNotification::build(makeSceneAction(['device_id' => 7, 'scene_id' => 3, 'action_type' => 'turn_off']));

    expect($payload->data['device_id'])->toBe('7');
});

test('data contains scene_id as string', function () {
    $payload = SmartHomeSceneActionFailedNotification::build(makeSceneAction(['device_id' => 1, 'scene_id' => 55, 'action_type' => 'toggle']));

    expect($payload->data['scene_id'])->toBe('55');
});

test('data never contains vibe_id — a Scene can be shared by multiple Vibes', function () {
    $payload = SmartHomeSceneActionFailedNotification::build(makeSceneAction(['device_id' => 1, 'scene_id' => 2, 'action_type' => 'turn_on']));

    expect($payload->data)->not->toHaveKey('vibe_id');
});

test('data contains action_type as string', function () {
    $payload = SmartHomeSceneActionFailedNotification::build(makeSceneAction(['device_id' => 1, 'scene_id' => 2, 'action_type' => 'set_brightness']));

    expect($payload->data['action_type'])->toBe('set_brightness');
});

test('all data values are strings', function () {
    $payload = SmartHomeSceneActionFailedNotification::build(makeSceneAction(['device_id' => 1, 'scene_id' => 2, 'action_type' => 'turn_on']));

    foreach ($payload->data as $value) {
        expect($value)->toBeString();
    }
});

test('payload contains no secrets or tokens', function () {
    $payload = SmartHomeSceneActionFailedNotification::build(makeSceneAction(['device_id' => 1, 'scene_id' => 2, 'action_type' => 'turn_on']));

    $serialised = json_encode([$payload->title, $payload->body, $payload->data]);

    foreach (['token', 'secret', 'credential', 'password', 'access_token'] as $forbidden) {
        expect(str_contains($serialised, $forbidden))->toBeFalse();
    }
});
