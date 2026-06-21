<?php

declare(strict_types=1);

namespace App\SmartHome\Services;

use App\Jobs\SmartHome\SmartHomeActionJob;
use App\Models\Vibe;
use App\Models\VibeDeviceAction;
use App\SmartHome\DTOs\SmartHomeDispatchResult;

/**
 * Dispatches one SmartHomeActionJob per vibe device action, in sort_order.
 *
 * Responsibilities:
 * - Load device actions for the given vibe, ordered by sort_order.
 * - Dispatch a SmartHomeActionJob for each action.
 * - Return a SmartHomeDispatchResult summary.
 *
 * Guarantees:
 * - Never calls ProviderAdapterResolver or HomeAssistantAdapter.
 * - Never makes HTTP requests.
 * - Actions with a missing device are skipped and counted in `skipped`.
 */
final class VibeSmartHomeDispatchService
{
    public function dispatch(Vibe $vibe): SmartHomeDispatchResult
    {
        $actions = VibeDeviceAction::where('vibe_id', $vibe->id)
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

            SmartHomeActionJob::dispatch($action->id);

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
}
