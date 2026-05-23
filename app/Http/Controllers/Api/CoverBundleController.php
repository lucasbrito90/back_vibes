<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CoverBundle\CreateCoverBundleWithUploadedFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCoverBundleRequest;
use App\Http\Requests\UpdateCoverBundleRequest;
use App\Http\Resources\CoverBundleResource;
use App\Models\CoverBundle;
use App\Models\PresetVibe;
use App\Services\Storage\SafeAssetDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

    public function store(StoreCoverBundleRequest $request, CreateCoverBundleWithUploadedFiles $createCoverBundle): JsonResponse
    {
        $bundle = $createCoverBundle(
            $request->resolvedMetadata(),
            $request->file('thumbnail_file'),
            $request->file('artwork_file'),
            $request->file('player_background_file'),
        );

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

    public function destroy(CoverBundle $coverBundle, SafeAssetDeletionService $safeAssetDeletion): JsonResponse
    {
        if ($this->coverBundleReferencedByPresetVibes($coverBundle)) {
            Log::warning('Cover bundle delete blocked: bundle is referenced by preset vibes', [
                'cover_bundle_id' => $coverBundle->id,
            ]);

            return response()->json([
                'message' => 'This cover bundle is currently used by one or more vibes and cannot be deleted.',
            ], 409);
        }

        if ($this->coverBundleUrlsReferencedByUserVibes($coverBundle)) {
            Log::warning('Cover bundle delete blocked: bundle URLs are referenced by user vibes', [
                'cover_bundle_id' => $coverBundle->id,
            ]);

            return response()->json([
                'message' => 'This cover bundle is currently used by one or more vibes and cannot be deleted.',
            ], 409);
        }

        $urls = [];
        foreach (['thumbnail_url', 'artwork_url', 'player_background_url'] as $field) {
            $value = $coverBundle->{$field};
            if (is_string($value) && trim($value) !== '') {
                $urls[] = trim($value);
            }
        }

        DB::transaction(static fn () => $coverBundle->delete());

        foreach ($safeAssetDeletion->deleteUrlsIfUnreferenced($urls) as $url => $status) {
            if ($status === SafeAssetDeletionService::STATUS_FAILED) {
                Log::warning('Cover bundle deleted from DB but Spaces object cleanup failed', [
                    'url' => $url,
                    'status' => $status,
                ]);
            }
        }

        return response()->json(['message' => 'Cover bundle deleted.']);
    }

    private function coverBundleReferencedByPresetVibes(CoverBundle $coverBundle): bool
    {
        if (! Schema::hasTable('preset_vibes')) {
            return false;
        }

        return PresetVibe::query()->where('cover_bundle_id', $coverBundle->id)->exists();
    }

    private function coverBundleUrlsReferencedByUserVibes(CoverBundle $coverBundle): bool
    {
        if (! Schema::hasTable('vibes')) {
            return false;
        }

        $columns = ['thumbnail_url', 'artwork_url', 'player_background_url'];

        foreach ($columns as $column) {
            if (! Schema::hasColumn('vibes', $column)) {
                continue;
            }

            $url = $coverBundle->{$column};
            if (! is_string($url) || trim($url) === '') {
                continue;
            }

            if (DB::table('vibes')->where($column, trim($url))->exists()) {
                return true;
            }
        }

        return false;
    }
}
