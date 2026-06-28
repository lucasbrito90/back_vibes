<?php

declare(strict_types=1);

use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\Notifications\AccountSecurityNoticeNotification;

// ─────────────────────────────────────────────────────────────────────────────
// AccountSecurityNoticeNotification — unit tests (Phase 8.5)
// ─────────────────────────────────────────────────────────────────────────────

test('build returns a NotificationPayload', function () {
    $payload = AccountSecurityNoticeNotification::build('Title', 'Body');

    expect($payload)->toBeInstanceOf(NotificationPayload::class);
});

test('title reflects the caller-supplied value', function () {
    $payload = AccountSecurityNoticeNotification::build('New sign-in', 'A new device signed in.');

    expect($payload->title)->toBe('New sign-in');
});

test('body reflects the caller-supplied value', function () {
    $payload = AccountSecurityNoticeNotification::build('Alert', 'Something happened.');

    expect($payload->body)->toBe('Something happened.');
});

test('data contains type=account_security_notice', function () {
    $payload = AccountSecurityNoticeNotification::build('T', 'B');

    expect($payload->data['type'])->toBe('account_security_notice');
});

test('data contains only the type key', function () {
    $payload = AccountSecurityNoticeNotification::build('T', 'B');

    expect(array_keys($payload->data))->toBe(['type']);
});

test('all data values are strings', function () {
    $payload = AccountSecurityNoticeNotification::build('T', 'B');

    foreach ($payload->data as $value) {
        expect($value)->toBeString();
    }
});

test('empty title and body are accepted (caller responsibility)', function () {
    $payload = AccountSecurityNoticeNotification::build('', '');

    expect($payload->title)->toBe('')
        ->and($payload->body)->toBe('');
});

test('payload contains no secrets', function () {
    $payload = AccountSecurityNoticeNotification::build('Security alert', 'Please review your account.');

    $serialised = json_encode([$payload->title, $payload->body, $payload->data]);

    foreach (['token', 'secret', 'credential', 'password', 'access_token'] as $forbidden) {
        expect(str_contains($serialised, $forbidden))->toBeFalse();
    }
});
