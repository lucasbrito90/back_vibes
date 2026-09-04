<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Jobs\SmartHome\SceneActionJob;
use App\Models\Scene;
use App\Models\SceneAction;
use App\SmartHome\DTOs\SceneDispatchResult;
use Illuminate\Support\Str;

/**
 * Dispatches one SceneActionJob per scene action, in sort_order.
 *
 * Responsibilities:
 * - Load scene actions for the given scene, ordered by sort_order.
 * - Dispatch a SceneActionJob for each action.
 * - Return a SceneDispatchResult summary.
 *
 * Guarantees:
 * - Never calls ProviderAdapterResolver or any provider adapter.
 * - Never makes HTTP requests.
 * - Actions with a missing device are skipped and counted in `skipped`.
 */
final class SceneDispatchService
{
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

        foreach ($actions as $action) {
            if ($action->device === null) {
                $skipped++;

                continue;
            }

            SceneActionJob::dispatch($action->id, $sceneExecutionId);

            $dispatched++;
            $actionIds[] = $action->id;
        }

        return new SceneDispatchResult(
            scene_id: $scene->id,
            dispatched: $dispatched,
            skipped: $skipped,
            action_ids: $actionIds,
            scene_execution_id: $sceneExecutionId,
        );
    }
}
