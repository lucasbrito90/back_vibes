<?php

declare(strict_types=1);

namespace App\PushNotifications\Services;

use App\Jobs\PushNotifications\PushNotificationJob;
use App\Models\User;
use App\PushNotifications\DTOs\NotificationPayload;
use Illuminate\Support\Facades\Log;

/**
 * Entry point for dispatching push notifications to a user.
 *
 * Design decision — always dispatch:
 * The service always dispatches PushNotificationJob without checking for active
 * tokens inline. The job handles the "no tokens" case with a safe log and no-op.
 * This keeps the service simple, avoids an extra DB query at call time, and
 * ensures consistent dispatch semantics regardless of token state at dispatch time
 * (a token may be registered between dispatch and job execution).
 *
 * Boundaries (Phase 7):
 * - Does not call PushProvider directly.
 * - Does not throw provider errors to the caller.
 * - Does not integrate Scheduler or Smart Home — those are Phase 8.
 * - Full user push tokens are never logged here — only user_id and payload title.
 *
 * References: ADR-017, ADR-020, ADR-021, spec.md §6.
 */
final class PushNotificationService
{
    /**
     * Dispatch a push notification to all active tokens for the given user.
     *
     * Fire-and-forget: the job handles delivery, failure, and token deactivation.
     * The caller is not blocked and receives no provider result.
     */
    public function sendToUser(User $user, NotificationPayload $payload): void
    {
        Log::info('PushNotificationService: dispatching push for user.', [
            'user_id' => $user->id,
            'title' => $payload->title,
        ]);

        PushNotificationJob::dispatch($user->id, $payload);
    }
}
