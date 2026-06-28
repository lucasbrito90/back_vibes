<?php

declare(strict_types=1);

namespace App\PushNotifications\Services;

use App\Models\ProviderConnection;
use App\Models\ScheduleExecution;
use App\Models\User;
use App\Models\VibeDeviceAction;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\Notifications\AccountSecurityNoticeNotification;
use App\PushNotifications\Notifications\ScheduleExecutionFailedNotification;
use App\PushNotifications\Notifications\SmartHomeActionFailedNotification;
use App\PushNotifications\Notifications\SmartHomeProviderUnreachableNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The single entry point for domain modules (Scheduler, Smart Home) to emit
 * push notifications (Phase 8, ADR-019).
 *
 * Domain modules MUST NOT call PushNotificationService, PushNotificationJob,
 * PushProvider, or build NotificationPayload directly. They call the typed
 * methods here. This keeps the Push subsystem fully decoupled from business
 * domains and centralises notification content + event taxonomy.
 *
 * Responsibilities (orchestration only):
 *   Domain event → NotificationPayload (via builder) → PushNotificationService::sendToUser()
 *
 * Boundaries:
 * - No HTTP, no queue logic, no FCM knowledge.
 * - No Scheduler or Smart Home domain logic.
 * - Never throws to the caller — a push failure must never break a domain flow.
 * - Never logs secrets, tokens, credentials, or raw payloads.
 *
 * Phase 8.5: payload assembly is delegated to dedicated builder classes inside
 * App\PushNotifications\Notifications\. This class only orchestrates.
 *
 * References: ADR-017, ADR-019, ADR-021, spec.md §6 / §8 / §9.
 */
final class PushNotificationEvents
{
    public function __construct(
        private readonly PushNotificationService $pushNotifications,
    ) {}

    /**
     * A scheduled execution failed to dispatch/run.
     */
    public function notifyScheduleExecutionFailed(User $user, ScheduleExecution $execution): void
    {
        $this->send(
            $user,
            ScheduleExecutionFailedNotification::build($execution),
            notificationType: 'schedule_execution_failed',
            context: ['schedule_id' => $execution->schedule_id],
        );
    }

    /**
     * A Smart Home device action could not be completed.
     */
    public function notifySmartHomeActionFailed(User $user, VibeDeviceAction $action): void
    {
        $this->send(
            $user,
            SmartHomeActionFailedNotification::build($action),
            notificationType: 'smart_home_action_failed',
            context: [
                'device_id' => $action->device_id,
                'vibe_id' => $action->vibe_id,
            ],
        );
    }

    /**
     * A Smart Home provider connection is unreachable.
     */
    public function notifySmartHomeProviderUnreachable(User $user, ProviderConnection $connection): void
    {
        $this->send(
            $user,
            SmartHomeProviderUnreachableNotification::build($connection),
            notificationType: 'smart_home_provider_unreachable',
            context: [
                'provider_connection_id' => $connection->id,
                'provider' => $connection->provider,
            ],
        );
    }

    /**
     * A security-related account notice with dynamic title/body.
     */
    public function notifyAccountSecurityNotice(User $user, string $title, string $body): void
    {
        $this->send(
            $user,
            AccountSecurityNoticeNotification::build($title, $body),
            notificationType: 'account_security_notice',
            context: [],
        );
    }

    /**
     * Dispatch the payload and log safe structured context only.
     *
     * Never rethrows — a push dispatch failure must not break the domain flow.
     *
     * @param  array<string, mixed>  $context
     */
    private function send(User $user, NotificationPayload $payload, string $notificationType, array $context): void
    {
        try {
            $this->pushNotifications->sendToUser($user, $payload);

            Log::info('PushNotificationEvents: notification queued.', array_filter([
                'user_id' => $user->id,
                'notification_type' => $notificationType,
                ...$context,
            ], static fn ($v) => $v !== null));
        } catch (Throwable $e) {
            Log::error('PushNotificationEvents: failed to queue notification.', [
                'user_id' => $user->id,
                'notification_type' => $notificationType,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
