<?php

declare(strict_types=1);

namespace App\Jobs\SmartHome;

use App\Models\SceneAction;
use App\PushNotifications\Services\PushNotificationEvents;
use App\SmartHome\ActionType;
use App\SmartHome\DTOs\ActionResult;
use App\SmartHome\Exceptions\UnsupportedSmartHomeActionException;
use App\SmartHome\ProviderAdapterResolver;
use App\SmartHome\Services\SceneActionExecutionRecorder;
use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use App\Telemetry\SmartHome\SmartHomeActionProvider;
use App\Telemetry\SmartHome\SmartHomeActionTelemetry;
use App\Telemetry\SmartHome\SmartHomeActionType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued job that executes a single Scene Smart Home action against its provider.
 *
 * One job per action — see ADR-023 per-action job isolation. Since v1.3.0 this
 * is the only Smart Home action job: Vibe dispatch resolves its actions from
 * the Scene linked via vibes.scene_id, so the vibe-scoped predecessor
 * (SmartHomeActionJob) and its VibeDeviceAction table were removed.
 *
 * Push notification on failure uses scene_id (not vibe_id) via
 * SmartHomeSceneActionFailedNotification — a Scene may be shared by multiple
 * Vibes or executed directly without any Vibe context.
 *
 * Queue: smart-home | Timeout: 30s | Tries: 3
 */
final class SceneActionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 30;

    public int $tries = 3;

    public function __construct(
        public readonly int $sceneActionId,
        public readonly ?string $sceneExecutionId = null,
    ) {
        $this->onQueue('smart-home');
    }

    public function handle(
        ProviderAdapterResolver $resolver,
        PushNotificationEvents $pushEvents,
        SmartHomeActionTelemetry $actionTelemetry,
        SceneActionExecutionRecorder $executionRecorder,
    ): void {
        $action = SceneAction::with(['device', 'device.providerConnection', 'device.user'])
            ->find($this->sceneActionId);

        if ($action === null) {
            Log::warning('SceneActionJob: action not found or deleted — skipping.', [
                'scene_action_id' => $this->sceneActionId,
            ]);

            return;
        }

        $device = $action->device;

        if ($device === null) {
            Log::warning('SceneActionJob: device missing for action — skipping.', [
                'scene_action_id' => $action->id,
                'scene_id' => $action->scene_id,
                'device_id' => $action->device_id,
            ]);

            return;
        }

        $connection = $device->providerConnection;

        if ($connection === null) {
            Log::warning('SceneActionJob: provider connection missing for device — skipping.', [
                'scene_action_id' => $action->id,
                'scene_id' => $action->scene_id,
                'device_id' => $device->id,
            ]);

            return;
        }

        $context = [
            'scene_action_id' => $action->id,
            'scene_id' => $action->scene_id,
            'device_id' => $device->id,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'action_type' => $action->action_type,
        ];

        $executedAt = now();

        $wrap = $actionTelemetry->wrapWithMetadata(
            SmartHomeActionProvider::fromProviderSlug($connection->provider),
            SmartHomeActionType::fromActionTypeSlug($action->action_type),
            function () use ($resolver, $connection, $device, $action) {
                if (ActionType::isBlockedByDeviceCapabilities($device->capabilities, $action->action_type)) {
                    throw UnsupportedSmartHomeActionException::forAction($action->action_type);
                }

                $adapter = $resolver->forProvider($connection->provider);

                return $adapter->executeAction(
                    $connection,
                    $device->provider_device_id,
                    $action->action_type,
                    $action->parameters ?? [],
                );
            },
            fn (ActionResult $result) => $result->success
                ? SmartHomeActionOutcome::Success
                : SmartHomeActionOutcome::Failure,
            fn (Throwable $e) => $e instanceof UnsupportedSmartHomeActionException
                ? SmartHomeActionOutcome::Unsupported
                : SmartHomeActionOutcome::Failure,
        );

        if ($wrap->thrownException instanceof UnsupportedSmartHomeActionException) {
            Log::warning('SceneActionJob: unsupported action type — skipping.', [
                ...$context,
                'outcome' => SmartHomeActionOutcome::Unsupported->value,
                'exception_class' => $wrap->thrownException::class,
            ]);

            $executionRecorder->record(
                sceneExecutionId: $this->sceneExecutionId,
                action: $action,
                device: $device,
                connection: $connection,
                outcome: $wrap->outcome,
                executedAt: $executedAt,
                exception: $wrap->thrownException,
                traceId: $wrap->traceId,
                durationMs: $wrap->durationMs,
            );

            return;
        }

        if ($wrap->thrownException !== null) {
            Log::error('SceneActionJob: unexpected error executing action.', [
                ...$context,
                'outcome' => SmartHomeActionOutcome::Failure->value,
                'exception_class' => $wrap->thrownException::class,
            ]);

            $this->notifyActionFailed($action, $pushEvents);

            $executionRecorder->record(
                sceneExecutionId: $this->sceneExecutionId,
                action: $action,
                device: $device,
                connection: $connection,
                outcome: $wrap->outcome,
                executedAt: $executedAt,
                exception: $wrap->thrownException,
                traceId: $wrap->traceId,
                durationMs: $wrap->durationMs,
            );

            return;
        }

        /** @var ActionResult $result */
        $result = $wrap->result;

        $this->logResult($context, $result);

        if (! $result->success) {
            $this->notifyActionFailed($action, $pushEvents);
        }

        $executionRecorder->record(
            sceneExecutionId: $this->sceneExecutionId,
            action: $action,
            device: $device,
            connection: $connection,
            outcome: $wrap->outcome,
            executedAt: $executedAt,
            actionResult: $result,
            traceId: $wrap->traceId,
            durationMs: $wrap->durationMs,
        );
    }

    /**
     * Emit a smart_home_scene_action_failed push to the device owner.
     */
    private function notifyActionFailed(SceneAction $action, PushNotificationEvents $pushEvents): void
    {
        $user = $action->device?->user;

        if ($user === null) {
            return;
        }

        $pushEvents->notifySceneActionFailed($user, $action);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logResult(array $context, ActionResult $result): void
    {
        if ($result->success) {
            return;
        }

        Log::warning('SceneActionJob: provider returned action failure.', [
            ...$context,
            'outcome' => SmartHomeActionOutcome::Failure->value,
            'status_code' => $result->status_code,
        ]);
    }
}
