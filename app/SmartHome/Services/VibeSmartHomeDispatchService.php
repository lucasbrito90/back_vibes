<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Models\SceneAction;
use App\Models\Vibe;
use App\SmartHome\DTOs\SmartHomeDispatchResult;
use App\SmartHome\ProviderDescriptorRegistry;
use App\SmartHome\ProviderExecutionCapability;
use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Dispatches one SceneActionJob per scene action linked to the vibe's Scene, in sort_order.
 *
 * Responsibilities:
 * - Resolve the vibe's linked Scene (if any) and load its actions ordered by sort_order.
 * - Dispatch a SceneActionJob for each action with a resolvable device.
 * - Return a SmartHomeDispatchResult summary (vibe_id remains the vibe context).
 *
 * Guarantees:
 * - Vibes without scene_id return an empty dispatch result (not an error).
 * - Never calls ProviderAdapterResolver or HomeAssistantAdapter.
 * - Never makes HTTP requests.
 * - Actions with a missing device are skipped and counted in `skipped`.
 * - Enqueuing goes through SceneActionDispatcher, which applies the
 *   action's delay_seconds (per action, absolute from dispatch).
 */
final class VibeSmartHomeDispatchService
{
    public function __construct(
        private readonly ProviderDescriptorRegistry $descriptorRegistry,
        private readonly SceneActionExecutionRecorder $executionRecorder,
        private readonly SceneActionDispatcher $actionDispatcher,
    ) {}

    /**
     * Dispatch SceneActionJob for each action in the vibe's linked Scene.
     *
     * @param  bool  $requireScheduledExecution  When true (scheduler context),
     *                                           actions whose provider does not declare ScheduledExecution are skipped
     *                                           and recorded with SmartHomeActionOutcome::SkippedUnsupportedExecution
     *                                           (ADR-036 Decision 5). When false (manual dispatch / vibe play — the
     *                                           default, and what VibeSmartHomeDispatchController passes), actions
     *                                           whose provider does not declare ServerSideExecution are instead
     *                                           collected into `device_action_ids` (ADR-036 Decision 7) rather than
     *                                           enqueued — the mobile runtime executes them and reports the outcome
     *                                           via POST /api/scene-action-executions/report.
     */
    public function dispatch(Vibe $vibe, bool $requireScheduledExecution = false): SmartHomeDispatchResult
    {
        $sceneExecutionId = (string) Str::uuid();

        if ($vibe->scene_id === null) {
            return new SmartHomeDispatchResult(
                vibe_id: $vibe->id,
                dispatched: 0,
                skipped: 0,
                action_ids: [],
                scene_execution_id: $sceneExecutionId,
            );
        }

        $actions = $this->resolveSceneActions($vibe);

        $dispatched = 0;
        $skipped = 0;
        $skippedUnsupported = 0;
        $actionIds = [];
        $deviceActionIds = [];

        foreach ($actions as $action) {
            if ($action->device === null) {
                $skipped++;

                continue;
            }

            if ($requireScheduledExecution) {
                if (! $this->providerSupportsScheduledExecution($action)) {
                    $skippedUnsupported++;
                    $this->recordSkippedUnsupported($sceneExecutionId, $action);

                    continue;
                }
            } elseif (! $this->providerSupportsServerSideExecution($action)) {
                $deviceActionIds[] = $action->id;

                continue;
            }

            $this->actionDispatcher->dispatch($action, $sceneExecutionId);

            $dispatched++;
            $actionIds[] = $action->id;
        }

        return new SmartHomeDispatchResult(
            vibe_id: $vibe->id,
            dispatched: $dispatched,
            skipped: $skipped,
            action_ids: $actionIds,
            scene_execution_id: $sceneExecutionId,
            skipped_unsupported_execution: $skippedUnsupported,
            device_action_ids: $deviceActionIds,
        );
    }

    /**
     * Check whether the action's provider declares ScheduledExecution.
     *
     * Resolved via ProviderDescriptorRegistry — capability, never a provider
     * slug comparison. Unknown slugs propagate as exceptions (caught by the
     * caller DispatchDueSchedulesCommand::dispatchSmartHomeAfterSchedule).
     */
    private function providerSupportsScheduledExecution(SceneAction $action): bool
    {
        $descriptor = $this->descriptorRegistry->forSlug($action->device->provider);

        return in_array(
            ProviderExecutionCapability::ScheduledExecution,
            $descriptor->executionCapabilities,
            true,
        );
    }

    /**
     * Check whether the action's provider declares ServerSideExecution.
     *
     * Resolved via ProviderDescriptorRegistry — capability, never a provider
     * slug comparison. Only consulted on the requireScheduledExecution=false
     * (manual/vibe-play) path; the scheduler path uses
     * providerSupportsScheduledExecution() instead, unchanged.
     */
    private function providerSupportsServerSideExecution(SceneAction $action): bool
    {
        $descriptor = $this->descriptorRegistry->forSlug($action->device->provider);

        return in_array(
            ProviderExecutionCapability::ServerSideExecution,
            $descriptor->executionCapabilities,
            true,
        );
    }

    /**
     * Record one scene_action_executions row for the skipped action.
     *
     * Delegates entirely to SceneActionExecutionRecorder (fail-open, never
     * throws). The connection may be null if loaded lazily and the FK was
     * cleaned up; in that case no row is written (the skip is already counted
     * in skipped_unsupported_execution on the DispatchResult).
     */
    private function recordSkippedUnsupported(string $sceneExecutionId, SceneAction $action): void
    {
        $device = $action->device;
        $connection = $device->relationLoaded('providerConnection')
            ? $device->getRelation('providerConnection')
            : $device->providerConnection;

        if ($connection === null) {
            return;
        }

        $this->executionRecorder->record(
            sceneExecutionId: $sceneExecutionId,
            action: $action,
            device: $device,
            connection: $connection,
            outcome: SmartHomeActionOutcome::SkippedUnsupportedExecution,
            executedAt: now(),
            attempt: 1,
        );
    }

    /**
     * @return Collection<int, SceneAction>
     */
    private function resolveSceneActions(Vibe $vibe): Collection
    {
        $scene = $vibe->relationLoaded('scene')
            ? $vibe->scene
            : $vibe->scene()->first();

        if ($scene === null) {
            return new Collection;
        }

        if ($scene->relationLoaded('actions')) {
            return $scene->actions;
        }

        return $scene->actions()
            ->with(['device', 'device.providerConnection'])
            ->orderBy('sort_order')
            ->get();
    }
}
