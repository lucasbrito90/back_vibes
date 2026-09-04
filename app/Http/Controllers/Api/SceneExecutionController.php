<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SceneActionExecutionResource;
use App\Http\Resources\SceneExecutionEventResource;
use App\Models\Scene;
use App\SmartHome\Services\SceneExecutionAggregationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only execution history for a Scene (ADR-034 §6).
 *
 * Distinct from SceneDispatchController, which reports enqueue state only.
 */
final class SceneExecutionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly SceneExecutionAggregationService $aggregationService,
    ) {}

    public function index(Request $request, int $scene): AnonymousResourceCollection
    {
        $scene = $this->findOwnedScene($request, $scene);

        $this->authorize('view', $scene);

        $events = $this->aggregationService->paginateEventsForScene(
            $scene->id,
            perPage: (int) $request->integer('per_page', 15),
        );

        return SceneExecutionEventResource::collection($events);
    }

    public function show(Request $request, int $scene, string $sceneExecutionId): JsonResponse
    {
        $scene = $this->findOwnedScene($request, $scene);

        $this->authorize('view', $scene);

        $summary = $this->aggregationService->summarizeExecution($scene->id, $sceneExecutionId);

        if ($summary === null) {
            abort(404);
        }

        $actions = $this->aggregationService->actionRowsForExecution($scene->id, $sceneExecutionId);

        return response()->json([
            'data' => [
                'scene_execution_id' => $summary['scene_execution_id'],
                'scene_id' => $summary['scene_id'],
                'state' => $summary['state']->value,
                'count_success' => $summary['count_success'],
                'count_non_success' => $summary['count_non_success'],
                'count_total' => $summary['count_total'],
                'executed_at' => $summary['executed_at'],
                'by_provider' => $summary['by_provider'],
                'actions' => SceneActionExecutionResource::collection($actions),
            ],
        ]);
    }

    private function findOwnedScene(Request $request, int $sceneId): Scene
    {
        return Scene::where('user_id', $request->user()->id)->findOrFail($sceneId);
    }
}
