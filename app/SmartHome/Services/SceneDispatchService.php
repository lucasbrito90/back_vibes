<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Models\Scene;
use App\Models\SceneAction;
use App\SmartHome\DTOs\SceneDispatchResult;
use App\SmartHome\ProviderDescriptorRegistry;
use App\SmartHome\ProviderExecutionCapability;
use Illuminate\Support\Str;

/**
 * Dispatches one SceneActionJob per scene action, in sort_order — except
 * actions whose provider does not declare ServerSideExecution (ADR-036
 * Decision 7), which are returned as device-side work instead of enqueued.
 *
 * Responsibilities:
 * - Load scene actions for the given scene, ordered by sort_order.
 * - Dispatch a SceneActionJob for each action whose provider declares
 *   ServerSideExecution.
 * - Collect the rest as `device_action_ids` — the mobile runtime executes
 *   them locally and reports the outcome via
 *   POST /api/scene-action-executions/report.
 * - Return a SceneDispatchResult summary.
 *
 * Guarantees:
 * - Never calls ProviderAdapterResolver or any provider adapter.
 * - Never makes HTTP requests.
 * - Actions with a missing device are skipped and counted in `skipped`.
 * - Enqueuing goes through SceneActionDispatcher, which applies the
 *   action's delay_seconds (per action, absolute from dispatch).
 * - The device/server-side split is resolved purely from
 *   ProviderDescriptorRegistry's execution_capabilities — never a provider
 *   slug comparison (ProviderExtensibilityBoundaryTest enforces this).
 */
final class SceneDispatchService
{
    public function __construct(
        private readonly ProviderDescriptorRegistry $descriptorRegistry,
        private readonly SceneActionDispatcher $actionDispatcher,
    ) {}

    public function dispatch(Scene $scene): SceneDispatchResult
    {
        $sceneExecutionId = (string) Str::uuid();

        $actions = SceneAction::where('scene_id', $scene->id)
            ->with('device')
            ->orderBy('sort_order')
            ->get();

        $dispatched = 0;
        $skipped = 0;
        $actionIds = [];
        $deviceActionIds = [];

        foreach ($actions as $action) {
            if ($action->device === null) {
                $skipped++;

                continue;
            }

            if (! $this->providerSupportsServerSideExecution($action)) {
                $deviceActionIds[] = $action->id;

                continue;
            }

            $this->actionDispatcher->dispatch($action, $sceneExecutionId);

            $dispatched++;
            $actionIds[] = $action->id;
        }

        return new SceneDispatchResult(
            scene_id: $scene->id,
            dispatched: $dispatched,
            skipped: $skipped,
            action_ids: $actionIds,
            scene_execution_id: $sceneExecutionId,
            device_action_ids: $deviceActionIds,
        );
    }

    /**
     * Check whether the action's provider declares ServerSideExecution.
     *
     * Resolved via ProviderDescriptorRegistry — capability, never a provider
     * slug comparison. Mirrors VibeSmartHomeDispatchService's identically
     * named method for the manual-dispatch path.
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
}
