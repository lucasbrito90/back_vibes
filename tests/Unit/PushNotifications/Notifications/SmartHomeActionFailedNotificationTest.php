<?php

declare(strict_types=1);

use App\Models\VibeDeviceAction;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\Notifications\SmartHomeActionFailedNotification;

// ─────────────────────────────────────────────────────────────────────────────
// SmartHomeActionFailedNotification — unit tests (Phase 8.5)
// ─────────────────────────────────────────────────────────────────────────────

function makeAction(array $attributes = []): VibeDeviceAction
{
    $action = new VibeDeviceAction;
    foreach ($attributes as $key => $value) {
        $action->$key = $value;
    }

    return $action;
}

test('build returns a NotificationPayload', function () {
    $action = makeAction(['device_id' => 1, 'vibe_id' => 2, 'action_type' => 'turn_on']);

    expect(SmartHomeActionFailedNotification::build($action))->toBeInstanceOf(NotificationPayload::class);
});

test('title is "Device action failed"', function () {
    $payload = SmartHomeActionFailedNotification::build(makeAction(['device_id' => 1, 'vibe_id' => 2, 'action_type' => 'turn_on']));

    expect($payload->title)->toBe('Device action failed');
});

test('body is the expected string', function () {
    $payload = SmartHomeActionFailedNotification::build(makeAction(['device_id' => 1, 'vibe_id' => 2, 'action_type' => 'turn_on']));

    expect($payload->body)->toBe('A Smart Home action could not be completed.');
});

test('data contains type=smart_home_action_failed', function () {
    $payload = SmartHomeActionFailedNotification::build(makeAction(['device_id' => 1, 'vibe_id' => 2, 'action_type' => 'turn_on']));

    expect($payload->data['type'])->toBe('smart_home_action_failed');
});

test('data contains device_id as string', function () {
    $payload = SmartHomeActionFailedNotification::build(makeAction(['device_id' => 7, 'vibe_id' => 3, 'action_type' => 'turn_off']));

    expect($payload->data['device_id'])->toBe('7');
});

test('data contains vibe_id as string', function () {
    $payload = SmartHomeActionFailedNotification::build(makeAction(['device_id' => 1, 'vibe_id' => 55, 'action_type' => 'toggle']));

    expect($payload->data['vibe_id'])->toBe('55');
});

test('data contains action_type as string', function () {
    $payload = SmartHomeActionFailedNotification::build(makeAction(['device_id' => 1, 'vibe_id' => 2, 'action_type' => 'set_brightness']));

    expect($payload->data['action_type'])->toBe('set_brightness');
});

test('all data values are strings', function () {
    $payload = SmartHomeActionFailedNotification::build(makeAction(['device_id' => 1, 'vibe_id' => 2, 'action_type' => 'turn_on']));

    foreach ($payload->data as $value) {
        expect($value)->toBeString();
    }
});

test('payload contains no secrets or tokens', function () {
    $payload = SmartHomeActionFailedNotification::build(makeAction(['device_id' => 1, 'vibe_id' => 2, 'action_type' => 'turn_on']));

    $serialised = json_encode([$payload->title, $payload->body, $payload->data]);

    foreach (['token', 'secret', 'credential', 'password', 'access_token'] as $forbidden) {
        expect(str_contains($serialised, $forbidden))->toBeFalse();
    }
});
