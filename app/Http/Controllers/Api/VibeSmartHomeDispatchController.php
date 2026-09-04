<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vibe;
use App\SmartHome\DTOs\SmartHomeDispatchResult;
use App\SmartHome\Services\VibeSmartHomeDispatchService;
use App\Telemetry\SmartHome\SmartHomeDispatchEntryPoint;
use App\Telemetry\SmartHome\SmartHomeDispatchTelemetry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles a vibe play trigger from mobile.
 *
 * POST /api/vibes/{vibe}/smart-home/dispatch
 *
 * - Authorises via VibePolicy (view permission — user must own the vibe).
 * - Delegates job dispatching to VibeSmartHomeDispatchService.
 * - Returns a dispatch summary.
 * - Never calls Home Assistant or any provider adapter.
 * - Never blocks audio playback on mobile.
 *
 * Phase 7B.4.2 wraps the dispatch() call with the `smart_home.dispatch`
 * Business Span, tagged `ixora.dispatch.entry_point=manual` — this
 * controller is the only place that knows "manual" is the right
 * classification for this call; VibeSmartHomeDispatchService itself is
 * unmodified and unaware telemetry exists (see
 * backend-smart-home-dispatch-boundary.md).
 */
final class VibeSmartHomeDispatchController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly VibeSmartHomeDispatchService $dispatchService,
        private readonly SmartHomeDispatchTelemetry $dispatchTelemetry,
    ) {}

    public function __invoke(Request $request, Vibe $vibe): JsonResponse
    {
        $this->authorize('view', $vibe);

        $result = $this->dispatchTelemetry->wrap(
            SmartHomeDispatchEntryPoint::Manual,
            fn () => $this->dispatchService->dispatch($vibe),
            fn (SmartHomeDispatchResult $result) => [$result->dispatched, $result->skipped],
        );

        return response()->json([
            'data' => [
                'vibe_id' => $result->vibe_id,
                'dispatched' => $result->dispatched,
                'skipped' => $result->skipped,
                'action_ids' => $result->action_ids,
                'scene_execution_id' => $result->scene_execution_id,
            ],
        ]);
    }
}
