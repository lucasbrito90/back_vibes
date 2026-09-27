<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVibeCategoryRequest;
use App\Http\Requests\SyncPresetVibeCategoriesRequest;
use App\Http\Requests\UpdateVibeCategoryRequest;
use App\Http\Resources\PresetVibeResource;
use App\Http\Resources\VibeCategoryResource;
use App\Models\PresetVibe;
use App\Models\VibeCategory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class VibeCategoryController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', VibeCategory::class);

        $query = VibeCategory::query()->orderBy('sort_order')->orderBy('id');

        $includeInactive = $request->boolean('include_inactive')
            && ($request->user()?->isAdminApproved() ?? false);

        if (! $includeInactive) {
            $query->where('is_active', true);
        }

        return VibeCategoryResource::collection($query->get());
    }

    public function show(Request $request, VibeCategory $vibeCategory): VibeCategoryResource
    {
        $this->authorize('view', $vibeCategory);

        if (! $vibeCategory->is_active && ! ($request->user()?->isAdminApproved() ?? false)) {
            abort(404);
        }

        return new VibeCategoryResource($vibeCategory);
    }

    public function store(StoreVibeCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', VibeCategory::class);

        $validated = $request->validated();

        $category = VibeCategory::query()->create([
            'slug' => $validated['slug'],
            'names' => $validated['names'],
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : true,
        ]);

        return (new VibeCategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(UpdateVibeCategoryRequest $request, VibeCategory $vibeCategory): VibeCategoryResource
    {
        $this->authorize('update', $vibeCategory);

        $validated = $request->validated();
        $payload = [];

        if (array_key_exists('names', $validated)) {
            $payload['names'] = $validated['names'];
        }

        if (array_key_exists('sort_order', $validated)) {
            $payload['sort_order'] = (int) $validated['sort_order'];
        }

        if (array_key_exists('is_active', $validated)) {
            $payload['is_active'] = (bool) $validated['is_active'];
        }

        if ($payload !== []) {
            $vibeCategory->update($payload);
        }

        return new VibeCategoryResource($vibeCategory->fresh());
    }

    public function destroy(VibeCategory $vibeCategory): JsonResponse
    {
        $this->authorize('delete', $vibeCategory);

        if ($vibeCategory->vibes()->exists() || $vibeCategory->presetVibes()->exists()) {
            return response()->json([
                'message' => 'This vibe category is currently used by one or more vibes or preset vibes and cannot be deleted.',
            ], 409);
        }

        $vibeCategory->delete();

        return response()->json(['message' => 'Vibe category deleted.']);
    }

    public function syncPresetCategories(
        SyncPresetVibeCategoriesRequest $request,
        PresetVibe $presetVibe,
    ): PresetVibeResource {
        $this->authorize('update', $presetVibe);

        $categoryIds = $request->validated()['category_ids'];

        DB::transaction(function () use ($presetVibe, $categoryIds): void {
            $presetVibe->categories()->sync($categoryIds);
        });

        return new PresetVibeResource(
            $presetVibe->fresh()->load(['coverBundle', 'presetVibeSounds.sound', 'categories']),
        );
    }
}
