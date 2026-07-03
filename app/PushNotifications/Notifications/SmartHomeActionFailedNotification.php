<?php

declare(strict_types=1);

namespace App\PushNotifications\Notifications;

use App\Models\VibeDeviceAction;
use App\PushNotifications\DTOs\NotificationPayload;

/**
 * Builds the NotificationPayload for a smart_home_action_failed event.
 *
 * Payload content (ADR-019, Phase 8.5 builder refactor):
 *   title: "Device action failed"
 *   body:  "A Smart Home action could not be completed."
 *   data:  type, device_id, vibe_id, action_type
 *
 * All data values are strings. No secrets.
 *
 * Phase 6A — schedule_id alignment note (ADR-024):
 * schedule_id is intentionally absent. SmartHomeActionJob receives only a
 * VibeDeviceAction; it has no access to the Schedule that triggered the dispatch.
 * Propagating schedule_id would require threading it through
 * VibeSmartHomeDispatchService → SmartHomeActionJob, changing the Smart Home
 * runtime — outside the boundary of Phase 6A. Deferred per ADR-024.
 */
final class SmartHomeActionFailedNotification
{
    public static function build(VibeDeviceAction $action): NotificationPayload
    {
        return new NotificationPayload(
            title: 'Device action failed',
            body: 'A Smart Home action could not be completed.',
            data: [
                'type' => 'smart_home_action_failed',
                'device_id' => (string) $action->device_id,
                'vibe_id' => (string) $action->vibe_id,
                'action_type' => (string) $action->action_type,
            ],
        );
    }
}
