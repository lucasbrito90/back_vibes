<?php

declare(strict_types=1);

namespace App\PushNotifications\Notifications;

use App\PushNotifications\DTOs\NotificationPayload;

/**
 * Builds the NotificationPayload for an account_security_notice event.
 *
 * Payload content (ADR-019, Phase 8.5 builder refactor):
 *   title: caller-supplied (dynamic)
 *   body:  caller-supplied (dynamic)
 *   data:  type only — no sensitive context in data
 *
 * Callers are responsible for keeping title/body free of secrets.
 */
final class AccountSecurityNoticeNotification
{
    public static function build(string $title, string $body): NotificationPayload
    {
        return new NotificationPayload(
            title: $title,
            body: $body,
            data: ['type' => 'account_security_notice'],
        );
    }
}
