<?php

declare(strict_types=1);

use App\Models\ProviderConnection;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\Notifications\SmartHomeProviderUnreachableNotification;

// ─────────────────────────────────────────────────────────────────────────────
// SmartHomeProviderUnreachableNotification — unit tests (Phase 8.5)
// ─────────────────────────────────────────────────────────────────────────────

function makeConnection(array $attributes = []): ProviderConnection
{
    $connection = new ProviderConnection;
    foreach ($attributes as $key => $value) {
        $connection->$key = $value;
    }

    return $connection;
}

test('build returns a NotificationPayload', function () {
    $connection = makeConnection(['id' => 1, 'provider' => 'home_assistant']);

    expect(SmartHomeProviderUnreachableNotification::build($connection))->toBeInstanceOf(NotificationPayload::class);
});

test('title is "Smart Home unavailable"', function () {
    $payload = SmartHomeProviderUnreachableNotification::build(makeConnection(['id' => 1, 'provider' => 'home_assistant']));

    expect($payload->title)->toBe('Smart Home unavailable');
});

test('body is the expected string', function () {
    $payload = SmartHomeProviderUnreachableNotification::build(makeConnection(['id' => 1, 'provider' => 'home_assistant']));

    expect($payload->body)->toBe('Your Smart Home provider is currently unreachable.');
});

test('data contains type=smart_home_provider_unreachable', function () {
    $payload = SmartHomeProviderUnreachableNotification::build(makeConnection(['id' => 1, 'provider' => 'home_assistant']));

    expect($payload->data['type'])->toBe('smart_home_provider_unreachable');
});

test('data contains provider_connection_id as string', function () {
    $payload = SmartHomeProviderUnreachableNotification::build(makeConnection(['id' => 42, 'provider' => 'home_assistant']));

    expect($payload->data['provider_connection_id'])->toBe('42');
});

test('data contains provider as string', function () {
    $payload = SmartHomeProviderUnreachableNotification::build(makeConnection(['id' => 1, 'provider' => 'home_assistant']));

    expect($payload->data['provider'])->toBe('home_assistant');
});

test('all data values are strings', function () {
    $payload = SmartHomeProviderUnreachableNotification::build(makeConnection(['id' => 1, 'provider' => 'home_assistant']));

    foreach ($payload->data as $value) {
        expect($value)->toBeString();
    }
});

test('payload contains no secrets or credentials', function () {
    $payload = SmartHomeProviderUnreachableNotification::build(makeConnection(['id' => 1, 'provider' => 'home_assistant']));

    $serialised = json_encode([$payload->title, $payload->body, $payload->data]);

    foreach (['token', 'secret', 'credential', 'password', 'access_token', 'encrypted'] as $forbidden) {
        expect(str_contains($serialised, $forbidden))->toBeFalse();
    }
});
