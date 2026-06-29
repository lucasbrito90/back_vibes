<?php

declare(strict_types=1);

namespace App\PushNotifications\Providers;

use App\Models\PushToken;
use App\PushNotifications\Contracts\PushProvider;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\DTOs\PushResult;
use Illuminate\Support\Facades\Log;

/**
 * Dry-run push provider for automated tests and local development without
 * Firebase credentials (ADR-017).
 *
 * Behavior:
 * - Makes NO HTTP calls and contacts no external service.
 * - Always returns a successful PushResult with provider "noop" and messageId null.
 * - Logs a safe, preview-only dry-run notice — never the full device token (ADR-021).
 *
 * Must never reach production accidentally — provider selection is config-driven
 * (config/push_notifications.php) and unsupported values fail explicitly.
 */
final class NoopPushProvider implements PushProvider
{
    private const PROVIDER = 'noop';

    public function send(PushToken $token, NotificationPayload $payload): PushResult
    {
        Log::info('Push notification dry-run (NoopPushProvider).', [
            'provider' => self::PROVIDER,
            'token_preview' => $token->tokenPreview(),
            'data_keys' => array_keys($payload->data),
        ]);

        return PushResult::success(
            provider: self::PROVIDER,
            statusCode: null,
            messageId: null,
            tokenPreview: $token->tokenPreview(),
        );
    }
}
