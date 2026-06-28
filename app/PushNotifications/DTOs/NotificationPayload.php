<?php

declare(strict_types=1);

namespace App\PushNotifications\DTOs;

/**
 * Immutable push notification payload passed to a PushProvider.
 *
 * `data` values must be strings — FCM data payloads are string key-value pairs.
 * No secrets are allowed in title, body, or data (ADR-021).
 *
 * References: ADR-017, ADR-021, spec.md §6.
 */
final readonly class NotificationPayload
{
    /**
     * @param  array<string, string>  $data  FCM data payload — string key-value pairs only
     * @param  array<string, mixed>|null  $androidConfig  Optional FCM AndroidConfig block
     */
    public function __construct(
        public string $title,
        public string $body,
        public array $data = [],
        public ?array $androidConfig = null,
    ) {}
}
