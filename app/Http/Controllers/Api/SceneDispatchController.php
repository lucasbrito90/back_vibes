<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Scene;
use App\SmartHome\Services\SceneDispatchService;
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
 */
final class SceneDispatchController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SceneDispatchService $dispatchService,
    ) {}

    public function __invoke(Request $request, int $scene): JsonResponse
    {
        $scene = $this->findOwnedScene($request, $scene);

        $this->authorize('view', $scene);

        $result = $this->dispatchService->dispatch($scene);

        return response()->json([
            'data' => [
                'scene_id' => $result->scene_id,
                'dispatched' => $result->dispatched,
                'skipped' => $result->skipped,
                'action_ids' => $result->action_ids,
            ],
        ]);
    }

    private function findOwnedScene(Request $request, int $sceneId): Scene
    {
        return Scene::where('user_id', $request->user()->id)->findOrFail($sceneId);
    }
}
