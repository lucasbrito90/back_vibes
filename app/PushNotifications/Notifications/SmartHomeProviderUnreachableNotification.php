<?php

declare(strict_types=1);

namespace App\PushNotifications\Notifications;

use App\Models\ProviderConnection;
use App\PushNotifications\DTOs\NotificationPayload;

/**
 * Builds the NotificationPayload for a smart_home_provider_unreachable event.
 *
 * Payload content (ADR-019, Phase 8.5 builder refactor):
 *   title: "Smart Home unavailable"
 *   body:  "Your Smart Home provider is currently unreachable."
 *   data:  type, provider_connection_id, provider
 *
 * All data values are strings. No secrets. provider_connection_id is an opaque
 * integer ID — the raw access token or credentials are never included.
 */
final class SmartHomeProviderUnreachableNotification
{
    public static function build(ProviderConnection $connection): NotificationPayload
    {
        return new NotificationPayload(
            title: 'Smart Home unavailable',
            body: 'Your Smart Home provider is currently unreachable.',
            data: [
                'type' => 'smart_home_provider_unreachable',
                'provider_connection_id' => (string) $connection->id,
                'provider' => (string) $connection->provider,
            ],
        );
    }
}
