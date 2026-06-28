<?php

declare(strict_types=1);

namespace App\PushNotifications\Contracts;

use App\Models\PushToken;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\DTOs\PushResult;

/**
 * Transport contract every push provider implements (FCM today; APNs / WebPush future).
 *
 * The provider operates on ONE token at a time. Fan-out to multiple user devices
 * is the responsibility of the notification service / job layer, never the provider
 * (ADR-017).
 *
 * Implementation rules:
 * - Must not know Scheduler or Smart Home domain logic.
 * - Must never log full device tokens — use PushToken::tokenPreview() (ADR-021).
 * - Returns a PushResult for delivery outcomes; throws only for provider/credential
 *   misconfiguration that cannot produce a per-token result.
 *
 * References: ADR-017, ADR-021, spec.md §6.
 */
interface PushProvider
{
    public function send(PushToken $token, NotificationPayload $payload): PushResult;
}
