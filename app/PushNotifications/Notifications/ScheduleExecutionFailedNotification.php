<?php

declare(strict_types=1);

namespace App\PushNotifications\Notifications;

use App\Models\ScheduleExecution;
use App\PushNotifications\DTOs\NotificationPayload;

/**
 * Builds the NotificationPayload for a schedule_execution_failed event.
 *
 * Payload content (ADR-019, Phase 8.5 builder refactor):
 *   title: "Schedule failed"
 *   body:  "One of your scheduled executions failed."
 *   data:  type, schedule_execution_id (when set), schedule_id (when set)
 *
 * All data values are strings. No secrets.
 */
final class ScheduleExecutionFailedNotification
{
    public static function build(ScheduleExecution $execution): NotificationPayload
    {
        $data = ['type' => 'schedule_execution_failed'];

        if ($execution->id !== null) {
            $data['schedule_execution_id'] = (string) $execution->id;
        }

        if ($execution->schedule_id !== null) {
            $data['schedule_id'] = (string) $execution->schedule_id;
        }

        return new NotificationPayload(
            title: 'Schedule failed',
            body: 'One of your scheduled executions failed.',
            data: $data,
        );
    }
}
