<?php

declare(strict_types=1);

namespace App\PushNotifications\Services;

use App\Models\PushToken;
use App\Models\User;
use App\PushNotifications\PushProvider;

/**
 * Manages FCM device token lifecycle: register (upsert), refresh, and deactivate.
 *
 * Token ownership:
 *   - A token has exactly one owner (unique DB constraint).
 *   - Re-registering a token under the currently authenticated user reassigns
 *     ownership to that user. This is the MVP safe behavior (ADR-018).
 *   - Full token is never logged. Use safeTokenContext() for structured logging.
 *
 * References: ADR-018, ADR-021, spec.md §6.
 */
final class PushTokenService
{
    /**
     * Register or reactivate a push token for the given user.
     *
     * Upserts by token value (unique). If the token existed under another user,
     * ownership is reassigned to the current authenticated user (MVP behavior — ADR-018).
     * Sets is_active=true, last_seen_at=now(), clears revoked_at.
     */
    public function register(User $user, array $payload): PushToken
    {
        $pushToken = PushToken::firstOrNew(['token' => $payload['token']]);

        $pushToken->user_id = $user->id;
        $pushToken->platform = $payload['platform'];
        $pushToken->provider = $payload['provider'] ?? PushProvider::Fcm->value;
        $pushToken->device_id = $payload['device_id'] ?? null;
        $pushToken->app_version = $payload['app_version'] ?? null;
        $pushToken->device_model = $payload['device_model'] ?? null;
        $pushToken->is_active = true;
        $pushToken->last_seen_at = now();
        $pushToken->revoked_at = null;

        $pushToken->save();

        return $pushToken;
    }

    /**
     * Handle FCM token rotation.
     *
     * If old_token is provided and belongs to the current user, it is deactivated.
     * If old_token belongs to another user, it is not touched.
     * The new token is registered/upserted for the current user.
     */
    public function refresh(User $user, array $payload): PushToken
    {
        if (! empty($payload['old_token'])) {
            $old = PushToken::where('token', $payload['old_token'])
                ->where('user_id', $user->id)
                ->first();

            if ($old !== null) {
                $this->deactivate($user, $old);
            }
            // old_token belonging to another user is intentionally not modified
        }

        return $this->register($user, $payload);
    }

    /**
     * Soft-deactivate a push token (logout or explicit unregister).
     *
     * Policy/controller ensures the caller owns the token before calling this.
     */
    public function deactivate(User $user, PushToken $pushToken): void
    {
        $pushToken->is_active = false;
        $pushToken->revoked_at = now();
        $pushToken->save();
    }

    /**
     * Safe structured context for logging — never includes the full token.
     *
     * @return array{id: int, token_preview: string, platform: string, provider: string}
     */
    public function safeTokenContext(PushToken $token): array
    {
        return [
            'id' => $token->id,
            'token_preview' => $token->tokenPreview(),
            'platform' => $token->platform,
            'provider' => $token->provider,
        ];
    }
}
