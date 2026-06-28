<?php

declare(strict_types=1);

use App\Models\ScheduleExecution;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\Notifications\ScheduleExecutionFailedNotification;

// ─────────────────────────────────────────────────────────────────────────────
// ScheduleExecutionFailedNotification — unit tests (Phase 8.5)
// ─────────────────────────────────────────────────────────────────────────────

function makeExecution(array $attributes = []): ScheduleExecution
{
    $execution = new ScheduleExecution;
    foreach ($attributes as $key => $value) {
        $execution->$key = $value;
    }

    return $execution;
}

test('build returns a NotificationPayload', function () {
    $execution = makeExecution(['id' => 1, 'schedule_id' => 10]);

    $payload = ScheduleExecutionFailedNotification::build($execution);

    expect($payload)->toBeInstanceOf(NotificationPayload::class);
});

test('title is "Schedule failed"', function () {
    $payload = ScheduleExecutionFailedNotification::build(makeExecution(['id' => 1, 'schedule_id' => 10]));

    expect($payload->title)->toBe('Schedule failed');
});

test('body is the expected string', function () {
    $payload = ScheduleExecutionFailedNotification::build(makeExecution(['id' => 1, 'schedule_id' => 10]));

    expect($payload->body)->toBe('One of your scheduled executions failed.');
});

test('data contains type=schedule_execution_failed', function () {
    $payload = ScheduleExecutionFailedNotification::build(makeExecution(['id' => 1, 'schedule_id' => 10]));

    expect($payload->data['type'])->toBe('schedule_execution_failed');
});

test('data contains schedule_execution_id as string when id is set', function () {
    $payload = ScheduleExecutionFailedNotification::build(makeExecution(['id' => 42, 'schedule_id' => 7]));

    expect($payload->data)->toHaveKey('schedule_execution_id')
        ->and($payload->data['schedule_execution_id'])->toBe('42');
});

test('data contains schedule_id as string when schedule_id is set', function () {
    $payload = ScheduleExecutionFailedNotification::build(makeExecution(['id' => 1, 'schedule_id' => 99]));

    expect($payload->data)->toHaveKey('schedule_id')
        ->and($payload->data['schedule_id'])->toBe('99');
});

test('data omits schedule_execution_id when id is null', function () {
    $payload = ScheduleExecutionFailedNotification::build(makeExecution(['id' => null, 'schedule_id' => 5]));

    expect($payload->data)->not->toHaveKey('schedule_execution_id');
});

test('data omits schedule_id when schedule_id is null', function () {
    $payload = ScheduleExecutionFailedNotification::build(makeExecution(['id' => 3, 'schedule_id' => null]));

    expect($payload->data)->not->toHaveKey('schedule_id');
});

test('all data values are strings', function () {
    $payload = ScheduleExecutionFailedNotification::build(makeExecution(['id' => 1, 'schedule_id' => 2]));

    foreach ($payload->data as $value) {
        expect($value)->toBeString();
    }
});

test('payload contains no secrets or tokens', function () {
    $payload = ScheduleExecutionFailedNotification::build(makeExecution(['id' => 1, 'schedule_id' => 10]));

    $serialised = json_encode([$payload->title, $payload->body, $payload->data]);

    foreach (['token', 'secret', 'credential', 'password', 'access_token'] as $forbidden) {
        expect(str_contains($serialised, $forbidden))->toBeFalse();
    }
});
