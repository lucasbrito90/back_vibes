<?php

declare(strict_types=1);

namespace App\Jobs\SmartHome;

use App\Models\VibeDeviceAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that will execute a single Smart Home device action.
 *
 * Phase 8 (stub): loads the action and logs intent only.
 * Phase 9 will resolve the provider adapter and call executeAction().
 *
 * Contract:
 * - Never calls ProviderAdapterResolver or HomeAssistantAdapter.
 * - Never makes HTTP requests.
 * - Handles a missing/soft-deleted action gracefully (no exception).
 * - Queue: smart-home | Timeout: 30s | Tries: 3
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

    public function handle(): void
    {
        $action = VibeDeviceAction::with(['device', 'device.providerConnection'])
            ->find($this->vibeDeviceActionId);

        if ($action === null) {
            Log::warning('SmartHomeActionJob: action not found or deleted — skipping.', [
                'vibe_device_action_id' => $this->vibeDeviceActionId,
            ]);

            return;
        }

        Log::info('SmartHomeActionJob: would execute action (Phase 8 stub — no provider call).', [
            'vibe_device_action_id' => $action->id,
            'vibe_id' => $action->vibe_id,
            'device_id' => $action->device_id,
            'device_name' => $action->device?->name,
            'action_type' => $action->action_type,
            'delay_seconds' => $action->delay_seconds,
            'sort_order' => $action->sort_order,
        ]);
    }
}
