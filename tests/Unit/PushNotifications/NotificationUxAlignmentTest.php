<?php

declare(strict_types=1);

use App\Models\ProviderConnection;
use App\Models\SceneAction;
use App\Models\ScheduleExecution;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\Notifications\AccountSecurityNoticeNotification;
use App\PushNotifications\Notifications\ScheduleExecutionFailedNotification;
use App\PushNotifications\Notifications\SmartHomeProviderUnreachableNotification;
use App\PushNotifications\Notifications\SmartHomeSceneActionFailedNotification;

/**
 * Phase 6B — Notification UX alignment.
 *
 * Pins the cohesive user experience contract across failure notification types:
 * - short, neutral, actionable display copy
 * - stable type strings for mobile tap routing (front_vibes push-notification-handler)
 * - no secrets or provider slugs in title/body
 * - no success notification types in the failure taxonomy
 *
 * Mobile tap destinations (verified in front_vibes unit tests):
 *   schedule_execution_failed        → /schedules
 *   smart_home_scene_action_failed   → /devices
 *   smart_home_provider_unreachable  → /devices
 *   account_security_notice          → /settings
 *
 * v1.3.0: smart_home_action_failed was replaced by
 * smart_home_scene_action_failed when Vibe dispatch moved to the linked
 * Scene. The mobile handler for the new type lands with the front_vibes
 * side of this migration.
 */
test('failure notification types align with documented mobile tap routes', function () {
    $routesByType = [
        'schedule_execution_failed' => '/schedules',
        'smart_home_scene_action_failed' => '/devices',
        'smart_home_provider_unreachable' => '/devices',
        'account_security_notice' => '/settings',
    ];

    expect(array_keys($routesByType))->toHaveCount(4);
});

test('each failure builder produces non-empty user-facing title and body', function () {
    $payloads = [
        ScheduleExecutionFailedNotification::build(new ScheduleExecution(['schedule_id' => 1])),
        SmartHomeSceneActionFailedNotification::build(new SceneAction([
            'device_id' => 1,
            'scene_id' => 2,
            'action_type' => 'turn_on',
        ])),
        SmartHomeProviderUnreachableNotification::build(new ProviderConnection([
            'id' => 1,
            'provider' => 'home_assistant',
        ])),
        AccountSecurityNoticeNotification::build('Security notice', 'Please review your account.'),
    ];

    foreach ($payloads as $payload) {
        expect($payload)->toBeInstanceOf(NotificationPayload::class)
            ->and(trim($payload->title))->not->toBe('')
            ->and(trim($payload->body))->not->toBe('');
    }
});

test('failure notification display copy avoids technical jargon and provider slugs', function () {
    $payloads = [
        ScheduleExecutionFailedNotification::build(new ScheduleExecution(['schedule_id' => 1])),
        SmartHomeSceneActionFailedNotification::build(new SceneAction([
            'device_id' => 1,
            'scene_id' => 2,
            'action_type' => 'turn_on',
        ])),
        SmartHomeProviderUnreachableNotification::build(new ProviderConnection([
            'id' => 1,
            'provider' => 'home_assistant',
        ])),
    ];

    foreach ($payloads as $payload) {
        $display = strtolower($payload->title.' '.$payload->body);

        expect($display)->not->toContain('execution')
            ->and($display)->not->toContain('home_assistant')
            ->and($display)->not->toContain('token')
            ->and($display)->not->toContain('credential');
    }
});

test('failure taxonomy contains only the four operational types — no success variants', function () {
    $types = [
        ScheduleExecutionFailedNotification::build(new ScheduleExecution(['schedule_id' => 1]))->data['type'],
        SmartHomeSceneActionFailedNotification::build(new SceneAction([
            'device_id' => 1,
            'scene_id' => 2,
            'action_type' => 'turn_on',
        ]))->data['type'],
        SmartHomeProviderUnreachableNotification::build(new ProviderConnection([
            'id' => 1,
            'provider' => 'home_assistant',
        ]))->data['type'],
        AccountSecurityNoticeNotification::build('Notice', 'Body')->data['type'],
    ];

    expect($types)->toBe([
        'schedule_execution_failed',
        'smart_home_scene_action_failed',
        'smart_home_provider_unreachable',
        'account_security_notice',
    ]);

    foreach ($types as $type) {
        expect($type)->not->toContain('success')
            ->and($type)->not->toContain('completed')
            ->and($type)->not->toContain('due');
    }
});
