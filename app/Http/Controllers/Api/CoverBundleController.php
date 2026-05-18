<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCoverBundleRequest;
use App\Http\Requests\UpdateCoverBundleRequest;
use App\Http\Resources\CoverBundleResource;
use App\Models\CoverBundle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CoverBundleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = CoverBundle::query()->orderBy('name');

        $includeInactive = $request->boolean('include_inactive')
            && ($request->user()?->isAdminApproved() ?? false);

        if (! $includeInactive) {
            $query->where('is_active', true);
        }

        return CoverBundleResource::collection($query->get());
    }

    public function show(Request $request, CoverBundle $coverBundle): CoverBundleResource
    {
        if (! $coverBundle->is_active && ! ($request->user()?->isAdminApproved() ?? false)) {
            abort(404);
        }

        return new CoverBundleResource($coverBundle);
    }

    public function store(StoreCoverBundleRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $bundle = CoverBundle::query()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'thumbnail_url' => $validated['thumbnail_url'] ?? null,
            'artwork_url' => $validated['artwork_url'] ?? null,
            'player_background_url' => $validated['player_background_url'] ?? null,
            'category' => $validated['category'] ?? null,
            'tags' => $request->resolvedTags(),
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : true,
        ]);

        return (new CoverBundleResource($bundle))->response()->setStatusCode(201);
    }

    public function update(UpdateCoverBundleRequest $request, CoverBundle $coverBundle): CoverBundleResource
    {
        $validated = $request->validated();
        $payload = [];

        foreach (['name', 'description', 'thumbnail_url', 'artwork_url', 'player_background_url', 'category'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        $tags = $request->resolvedTags();
        if ($tags !== null) {
            $payload['tags'] = $tags;
        }

        if (array_key_exists('is_active', $validated)) {
            $payload['is_active'] = (bool) $validated['is_active'];
        }

        if ($payload !== []) {
            $coverBundle->update($payload);
        }

        return new CoverBundleResource($coverBundle->fresh());
    }

    public function destroy(CoverBundle $coverBundle): JsonResponse
    {
        $coverBundle->delete();

        return response()->json(['message' => 'Cover bundle deleted.']);
    }
}
