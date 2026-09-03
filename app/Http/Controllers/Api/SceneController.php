<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSceneRequest;
use App\Http\Requests\UpdateSceneRequest;
use App\Http\Resources\SceneResource;
use App\Models\Scene;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SceneController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Scene::class);

        $scenes = Scene::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return SceneResource::collection($scenes);
    }

    public function store(StoreSceneRequest $request): SceneResource
    {
        $this->authorize('create', Scene::class);

        $scene = Scene::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return new SceneResource($scene);
    }

    public function show(Request $request, int $scene): SceneResource
    {
        $scene = $this->findOwnedScene($request, $scene);

        $this->authorize('view', $scene);

        return new SceneResource($scene);
    }

    public function update(UpdateSceneRequest $request, int $scene): SceneResource
    {
        $scene = $this->findOwnedScene($request, $scene);

        $this->authorize('update', $scene);

        $scene->update($request->validated());

        return new SceneResource($scene);
    }

    public function destroy(Request $request, int $scene): JsonResponse
    {
        $scene = $this->findOwnedScene($request, $scene);

        $this->authorize('delete', $scene);

        $scene->delete();

        return response()->json(['message' => 'Scene deleted.']);
    }

    private function findOwnedScene(Request $request, int $sceneId): Scene
    {
        return Scene::where('user_id', $request->user()->id)->findOrFail($sceneId);
    }
}
