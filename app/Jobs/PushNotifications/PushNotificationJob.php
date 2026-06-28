<?php

declare(strict_types=1);

namespace App\Jobs\PushNotifications;

use App\Models\PushToken;
use App\Models\User;
use App\PushNotifications\Contracts\PushProviderResolver;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\DTOs\PushResult;
use App\PushNotifications\Services\PushTokenService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fans out a NotificationPayload to all active push tokens of a user.
 *
 * Execution path:
 * - Loads the user with their active push tokens.
 * - Resolves the correct PushProvider for each token's provider slug.
 * - Calls PushProvider::send() once per token.
 * - Logs the PushResult safely (no full token, no credentials).
 * - Deactivates tokens reported as UNREGISTERED or NOT_FOUND by the provider.
 * - Catches per-token Throwable so one token failure never aborts the batch.
 *
 * Failure policy:
 * - One failed token does NOT fail the whole job — batch continues.
 * - Unexpected Throwables per token are caught, logged, and skipped.
 *
 * Invalid token deactivation:
 * - UNREGISTERED → deactivate immediately.
 * - NOT_FOUND    → deactivate immediately.
 * - INVALID_ARGUMENT → log warning only; do not deactivate (may be a payload issue).
 *
 * Privacy:
 * - Full device tokens are never logged (ADR-021).
 * - All log context uses PushResult fields or PushTokenService::safeTokenContext().
 *
 * Queue: "push" | Timeout: 30s | Tries: 3 (configurable via config/push_notifications.php)
 *
 * References: ADR-017, ADR-020, ADR-021, spec.md §6.
 */
final class PushNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Error codes that signal the token is permanently invalid and must be deactivated. */
    private const DEACTIVATABLE_ERROR_CODES = ['UNREGISTERED', 'NOT_FOUND'];

    public int $timeout;

    public int $tries;

    public function __construct(
        public readonly int $userId,
        public readonly NotificationPayload $payload,
    ) {
        $this->timeout = (int) config('push_notifications.queue.timeout', 30);
        $this->tries = (int) config('push_notifications.queue.tries', 3);
        $this->onQueue((string) config('push_notifications.queue.name', 'push'));
    }

    public function handle(PushProviderResolver $resolver, PushTokenService $tokenService): void
    {
        $user = User::with(['pushTokens' => fn ($q) => $q->active()])->find($this->userId);

        if ($user === null) {
            Log::warning('PushNotificationJob: user not found — skipping.', [
                'user_id' => $this->userId,
                'title' => $this->payload->title,
            ]);

            return;
        }

        $tokens = $user->pushTokens;

        if ($tokens->isEmpty()) {
            Log::info('PushNotificationJob: user has no active push tokens — skipping.', [
                'user_id' => $user->id,
            ]);

            return;
        }

        foreach ($tokens as $token) {
            $this->sendToToken($token, $resolver, $tokenService);
        }
    }

    private function sendToToken(
        PushToken $token,
        PushProviderResolver $resolver,
        PushTokenService $tokenService,
    ): void {
        try {
            $provider = $resolver->resolve($token->provider);
            $result = $provider->send($token, $this->payload);
            $this->logAndHandleResult($result, $token, $tokenService);
        } catch (Throwable $e) {
            Log::error('PushNotificationJob: unexpected error sending to token.', [
                'push_token_id' => $token->id,
                'token_preview' => $token->tokenPreview(),
                'provider' => $token->provider,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function logAndHandleResult(
        PushResult $result,
        PushToken $token,
        PushTokenService $tokenService,
    ): void {
        if ($result->success) {
            Log::info('PushNotificationJob: push delivered.', [
                'provider' => $result->provider,
                'message_id' => $result->messageId,
                'token_preview' => $result->tokenPreview,
            ]);

            return;
        }

        Log::warning('PushNotificationJob: push failed.', [
            'provider' => $result->provider,
            'status_code' => $result->statusCode,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'token_preview' => $result->tokenPreview,
        ]);

        if ($result->errorCode !== null
            && in_array($result->errorCode, self::DEACTIVATABLE_ERROR_CODES, true)
        ) {
            $tokenService->deactivateInvalidToken($token, (string) $result->errorCode);
        }
    }
}
