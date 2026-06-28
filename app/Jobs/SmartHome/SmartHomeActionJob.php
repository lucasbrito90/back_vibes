<?php

declare(strict_types=1);

namespace App\Jobs\SmartHome;

use App\Models\VibeDeviceAction;
use App\PushNotifications\Services\PushNotificationEvents;
use App\SmartHome\DTOs\ActionResult;
use App\SmartHome\Exceptions\UnsupportedSmartHomeActionException;
use App\SmartHome\ProviderAdapterResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued job that executes a single Smart Home device action against its
 * provider (Phase 9 — real execution, ADR-016 / ADR-013).
 *
 * Execution path:
 * - Loads the VibeDeviceAction with device + device.providerConnection.
 * - Resolves the provider adapter via ProviderAdapterResolver.
 * - Calls adapter->executeAction(connection, provider_device_id, action_type, parameters).
 *
 * Failure policy (MVP):
 * - A failed ActionResult (provider HTTP error / timeout) is a COMPLETED failure:
 *   it is logged and the job finishes successfully — it is NOT retried.
 * - An unsupported action is logged and the job finishes — retrying cannot help.
 * - Any unexpected Throwable is caught and logged so it never breaks the audio
 *   flow or floods the queue with retries.
 * - Provider credentials (access_token / encrypted_credentials) are NEVER logged.
 *
 * Queue: smart-home | Timeout: 30s | Tries: 3
 */
final class SmartHomeActionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    public function __construct(
        public readonly int $vibeDeviceActionId,
    ) {
        $this->onQueue('smart-home');
    }

    public function handle(ProviderAdapterResolver $resolver, PushNotificationEvents $pushEvents): void
    {
        $action = VibeDeviceAction::with(['device', 'device.providerConnection', 'device.user'])
            ->find($this->vibeDeviceActionId);

        if ($action === null) {
            Log::warning('SmartHomeActionJob: action not found or deleted — skipping.', [
                'vibe_device_action_id' => $this->vibeDeviceActionId,
            ]);

            return;
        }

        $device = $action->device;

        if ($device === null) {
            Log::warning('SmartHomeActionJob: device missing for action — skipping.', [
                'vibe_device_action_id' => $action->id,
                'vibe_id' => $action->vibe_id,
                'device_id' => $action->device_id,
            ]);

            return;
        }

        $connection = $device->providerConnection;

        if ($connection === null) {
            Log::warning('SmartHomeActionJob: provider connection missing for device — skipping.', [
                'vibe_device_action_id' => $action->id,
                'vibe_id' => $action->vibe_id,
                'device_id' => $device->id,
            ]);

            return;
        }

        $context = [
            'vibe_device_action_id' => $action->id,
            'vibe_id' => $action->vibe_id,
            'device_id' => $device->id,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'provider_device_id' => $device->provider_device_id,
            'action_type' => $action->action_type,
        ];

        try {
            $adapter = $resolver->forProvider($connection->provider);

            $result = $adapter->executeAction(
                $connection,
                $device->provider_device_id,
                $action->action_type,
                $action->parameters ?? [],
            );

            $this->logResult($context, $result);

            // Phase 8 — notify owner when the provider reported a failed action.
            // Decoupled via PushNotificationEvents; never affects execution outcome.
            if (! $result->success) {
                $this->notifyActionFailed($action, $pushEvents);
            }
        } catch (UnsupportedSmartHomeActionException $e) {
            Log::warning('SmartHomeActionJob: unsupported action — skipping.', [
                ...$context,
                'success' => false,
                'status_code' => null,
                'error_message' => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            // Catch-all: provider/infrastructure errors must never break the
            // audio flow. Logged and completed gracefully (no retry storm).
            Log::error('SmartHomeActionJob: unexpected error executing action.', [
                ...$context,
                'success' => false,
                'status_code' => null,
                'error_message' => $e->getMessage(),
            ]);

            // Phase 8 — an unexpected error also means the action could not complete.
            $this->notifyActionFailed($action, $pushEvents);
        }
    }

    /**
     * Emit a smart_home_action_failed push to the device owner.
     *
     * Decoupled: the job only knows PushNotificationEvents. Push errors are
     * swallowed inside that service and never affect the Smart Home flow.
     */
    private function notifyActionFailed(VibeDeviceAction $action, PushNotificationEvents $pushEvents): void
    {
        $user = $action->device?->user;

        if ($user === null) {
            return;
        }

        $pushEvents->notifySmartHomeActionFailed($user, $action);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logResult(array $context, ActionResult $result): void
    {
        $context = [
            ...$context,
            'success' => $result->success,
            'status_code' => $result->status_code,
            'error_message' => $result->error_message,
        ];

        if ($result->success) {
            Log::info('SmartHomeActionJob: action executed successfully.', $context);

            return;
        }

        Log::warning('SmartHomeActionJob: action execution failed (provider returned failure).', $context);
    }
}
