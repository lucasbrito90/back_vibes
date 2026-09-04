<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Scene;
use App\SmartHome\DTOs\SceneDispatchResult;
use App\SmartHome\Services\SceneDispatchService;
use App\Telemetry\SmartHome\SmartHomeDispatchEntryPoint;
use App\Telemetry\SmartHome\SmartHomeDispatchTelemetry;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handles manual Scene execution from mobile.
 *
 * POST /api/scenes/{scene}/execute
 *
 * - Resolves the scene via findOwnedScene() (404 for cross-user, T02/T03 pattern).
 * - Authorises via ScenePolicy (view permission).
 * - Delegates job dispatching to SceneDispatchService.
 * - Returns a dispatch summary without calling any provider adapter.
 *
 * Phase 7B.4.2 wraps the dispatch() call with the `smart_home.dispatch`
 * Business Span, tagged `ixora.dispatch.entry_point=scene_manual` — this
 * controller is the only place that knows "scene_manual" is the right
 * classification for this call; SceneDispatchService itself is unmodified
 * and unaware telemetry exists (see backend-smart-home-dispatch-boundary.md).
 */
final class SceneDispatchController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SceneDispatchService $dispatchService,
        private readonly SmartHomeDispatchTelemetry $dispatchTelemetry,
    ) {}

    public function __invoke(Request $request, int $scene): JsonResponse
    {
        $scene = $this->findOwnedScene($request, $scene);

        $this->authorize('view', $scene);

        $result = $this->dispatchTelemetry->wrap(
            SmartHomeDispatchEntryPoint::SceneManual,
            fn () => $this->dispatchService->dispatch($scene),
            fn (SceneDispatchResult $result) => [$result->dispatched, $result->skipped],
        );

        return response()->json([
            'data' => [
                'scene_id' => $result->scene_id,
                'dispatched' => $result->dispatched,
                'skipped' => $result->skipped,
                'action_ids' => $result->action_ids,
                'scene_execution_id' => $result->scene_execution_id,
            ],
        ]);
    }

    private function findOwnedScene(Request $request, int $sceneId): Scene
    {
        return Scene::where('user_id', $request->user()->id)->findOrFail($sceneId);
    }
}
