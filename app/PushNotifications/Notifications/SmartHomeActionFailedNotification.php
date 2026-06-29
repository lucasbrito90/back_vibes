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
