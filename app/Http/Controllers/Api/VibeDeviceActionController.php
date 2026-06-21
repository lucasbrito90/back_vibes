<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReorderVibeDeviceActionsRequest;
use App\Http\Requests\StoreVibeDeviceActionRequest;
use App\Http\Requests\UpdateVibeDeviceActionRequest;
use App\Http\Resources\VibeDeviceActionResource;
use App\Models\Vibe;
use App\Models\VibeDeviceAction;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class VibeDeviceActionController extends Controller
{
    use AuthorizesRequests;

    public function index(Vibe $vibe): AnonymousResourceCollection
    {
        $this->authorize('view', $vibe);

        $actions = $vibe->deviceActions()->with('device')->get();

        return VibeDeviceActionResource::collection($actions);
    }

    public function store(StoreVibeDeviceActionRequest $request, Vibe $vibe): JsonResponse
    {
        $this->authorize('update', $vibe);

        $data = $request->validated();

        $action = $vibe->deviceActions()->create([
            'device_id' => $data['device_id'],
            'action_type' => $data['action_type'],
            'parameters' => $data['parameters'] ?? null,
            'delay_seconds' => $data['delay_seconds'] ?? 0,
            'sort_order' => $data['sort_order'] ?? $this->nextSortOrder($vibe),
        ]);

        $action->load('device');

        return (new VibeDeviceActionResource($action))->response()->setStatusCode(201);
    }

    public function update(UpdateVibeDeviceActionRequest $request, Vibe $vibe, VibeDeviceAction $action): VibeDeviceActionResource
    {
        $this->authorize('update', $vibe);
        $this->ensureActionBelongsToVibe($vibe, $action);

        $action->fill($request->validated());
        $action->save();

        $action->load('device');

        return new VibeDeviceActionResource($action);
    }

    public function destroy(Vibe $vibe, VibeDeviceAction $action): JsonResponse
    {
        $this->authorize('update', $vibe);
        $this->ensureActionBelongsToVibe($vibe, $action);

        $action->delete();

        return response()->json(null, 204);
    }

    public function reorder(ReorderVibeDeviceActionsRequest $request, Vibe $vibe): AnonymousResourceCollection
    {
        $this->authorize('update', $vibe);

        $orderedIds = $request->validated()['ordered_ids'];

        DB::transaction(function () use ($vibe, $orderedIds) {
            foreach ($orderedIds as $position => $actionId) {
                $vibe->deviceActions()
                    ->whereKey($actionId)
                    ->update(['sort_order' => $position]);
            }
        });

        $actions = $vibe->deviceActions()->with('device')->get();

        return VibeDeviceActionResource::collection($actions);
    }

    private function nextSortOrder(Vibe $vibe): int
    {
        $max = $vibe->deviceActions()->max('sort_order');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    private function ensureActionBelongsToVibe(Vibe $vibe, VibeDeviceAction $action): void
    {
        abort_unless($action->vibe_id === $vibe->id, 404);
    }
}
