<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Jobs\SmartHome\SceneActionJob;
use App\Models\SceneAction;
use App\Models\Vibe;
use App\SmartHome\DTOs\SmartHomeDispatchResult;
use Illuminate\Database\Eloquent\Collection;

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
    public function dispatch(Vibe $vibe): SmartHomeDispatchResult
    {
        if ($vibe->scene_id === null) {
            return new SmartHomeDispatchResult(
                vibe_id: $vibe->id,
                dispatched: 0,
                skipped: 0,
                action_ids: [],
            );
        }

        $actions = $this->resolveSceneActions($vibe);

        $dispatched = 0;
        $skipped = 0;
        $actionIds = [];

        foreach ($actions as $action) {
            if ($action->device === null) {
                $skipped++;

                continue;
            }

            SceneActionJob::dispatch($action->id);

            $dispatched++;
            $actionIds[] = $action->id;
        }

        return new SmartHomeDispatchResult(
            vibe_id: $vibe->id,
            dispatched: $dispatched,
            skipped: $skipped,
            action_ids: $actionIds,
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
            ->with('device')
            ->orderBy('sort_order')
            ->get();
    }
}
