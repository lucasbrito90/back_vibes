<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Jobs\SmartHome\SceneActionJob;
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
 */
final class VibeSmartHomeDispatchService
{
    public function __construct(
        private readonly ProviderDescriptorRegistry $descriptorRegistry,
        private readonly SceneActionExecutionRecorder $executionRecorder,
    ) {}

    /**
     * Dispatch SceneActionJob for each action in the vibe's linked Scene.
     *
     * @param  bool  $requireScheduledExecution  When true (scheduler context),
     *                                           actions whose provider does not declare ScheduledExecution are skipped
     *                                           and recorded with SmartHomeActionOutcome::SkippedUnsupportedExecution
     *                                           (ADR-036 Decision 5). Default false preserves the existing behaviour
     *                                           for all callers (manual dispatch via VibeSmartHomeDispatchController
     *                                           passes no value and is unaffected).
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

        foreach ($actions as $action) {
            if ($action->device === null) {
                $skipped++;

                continue;
            }

            if ($requireScheduledExecution && ! $this->providerSupportsScheduledExecution($action)) {
                $skippedUnsupported++;
                $this->recordSkippedUnsupported($sceneExecutionId, $action);

                continue;
            }

            SceneActionJob::dispatch($action->id, $sceneExecutionId);

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
