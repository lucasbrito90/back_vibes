<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderSceneActionsRequest;
use App\Http\Requests\StoreSceneActionRequest;
use App\Http\Requests\UpdateSceneActionRequest;
use App\Http\Resources\SceneActionResource;
use App\Models\Scene;
use App\Models\SceneAction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class SceneActionController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, int $scene): AnonymousResourceCollection
    {
        $scene = $this->findOwnedScene($request, $scene);

        $this->authorize('view', $scene);

        $actions = $scene->actions()->with('device')->get();

        return SceneActionResource::collection($actions);
    }

    public function store(StoreSceneActionRequest $request, int $scene): JsonResponse
    {
        $scene = $this->findOwnedScene($request, $scene);

        $this->authorize('update', $scene);

        $data = $request->validated();

        $action = $scene->actions()->create([
            'device_id' => $data['device_id'],
            'action_type' => $data['action_type'],
            'parameters' => $data['parameters'] ?? null,
            'delay_seconds' => $data['delay_seconds'] ?? 0,
            'sort_order' => $data['sort_order'] ?? $this->nextSortOrder($scene),
        ]);

        $action->load('device');

        return (new SceneActionResource($action))->response()->setStatusCode(201);
    }

    public function update(UpdateSceneActionRequest $request, int $scene, SceneAction $action): SceneActionResource
    {
        $scene = $this->findOwnedScene($request, $scene);

        $this->authorize('update', $scene);
        $this->ensureActionBelongsToScene($scene, $action);

        $action->fill($request->validated());
        $action->save();

        $action->load('device');

        return new SceneActionResource($action);
    }

    public function destroy(Request $request, int $scene, SceneAction $action): JsonResponse
    {
        $scene = $this->findOwnedScene($request, $scene);

        $this->authorize('update', $scene);
        $this->ensureActionBelongsToScene($scene, $action);

        $action->delete();

        return response()->json(null, 204);
    }

    public function reorder(ReorderSceneActionsRequest $request, int $scene): AnonymousResourceCollection
    {
        $scene = $this->findOwnedScene($request, $scene);

        $this->authorize('update', $scene);

        $orderedIds = $request->validated()['ordered_ids'];

        DB::transaction(function () use ($scene, $orderedIds) {
            foreach ($orderedIds as $position => $actionId) {
                $scene->actions()
                    ->whereKey($actionId)
                    ->update(['sort_order' => $position]);
            }
        });

        $actions = $scene->actions()->with('device')->get();

        return SceneActionResource::collection($actions);
    }

    private function findOwnedScene(Request $request, int $sceneId): Scene
    {
        return Scene::where('user_id', $request->user()->id)->findOrFail($sceneId);
    }

    private function nextSortOrder(Scene $scene): int
    {
        $max = $scene->actions()->max('sort_order');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    private function ensureActionBelongsToScene(Scene $scene, SceneAction $action): void
    {
        abort_unless($action->scene_id === $scene->id, 404);
    }
}
