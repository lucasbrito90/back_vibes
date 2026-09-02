<?php

declare(strict_types=1);

namespace App\PushNotifications\Notifications;

use App\Models\SceneAction;
use App\PushNotifications\DTOs\NotificationPayload;

/**
 * Builds the NotificationPayload for a smart_home_scene_action_failed event.
 *
 * Payload content:
 *   title: "Device action failed"
 *   body:  "A Smart Home action could not be completed."
 *   data:  type, device_id, scene_id, action_type
 *
 * Uses scene_id (not vibe_id) because SceneAction is scene-scoped and a Scene
 * may be shared by multiple Vibes or executed directly without any Vibe context.
 */
final class SmartHomeSceneActionFailedNotification
{
    public static function build(SceneAction $action): NotificationPayload
    {
        return new NotificationPayload(
            title: 'Device action failed',
            body: 'A Smart Home action could not be completed.',
            data: [
                'type' => 'smart_home_scene_action_failed',
                'device_id' => (string) $action->device_id,
                'scene_id' => (string) $action->scene_id,
                'action_type' => (string) $action->action_type,
            ],
        );
    }
}
