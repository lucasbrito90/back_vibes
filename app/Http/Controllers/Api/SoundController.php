<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSoundRequest;
use App\Http\Requests\UpdateSoundRequest;
use App\Http\Resources\SoundResource;
use App\Models\Sound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class SoundController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $sounds = Sound::orderBy('name')->get();

        return SoundResource::collection($sounds);
    }

    public function show(Sound $sound): SoundResource
    {
        return new SoundResource($sound);
    }

    public function store(StoreSoundRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $fileUrl = trim((string) $validated['file_url']);
        if ($fileUrl === '') {
            throw ValidationException::withMessages([
                'file_url' => ['A file URL is required.'],
            ]);
        }

        $thumbnailUrl = $validated['thumbnail_url'] ?? null;
        $thumbnailUrl = is_string($thumbnailUrl) && trim($thumbnailUrl) !== ''
            ? trim($thumbnailUrl)
            : null;

        $sound = Sound::query()->create([
            'name' => $validated['name'],
            'category' => $validated['category'],
            'file_url' => $fileUrl,
            'thumbnail_url' => $thumbnailUrl,
            'duration' => $request->resolvedDuration(),
            'tags' => $request->resolvedTags(),
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : true,
        ]);

        return (new SoundResource($sound))->response()->setStatusCode(201);
    }

    public function update(UpdateSoundRequest $request, Sound $sound): SoundResource
    {
        $validated = $request->validated();
        $payload = [];

        foreach (['name', 'category'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('file_url', $validated)) {
            $fileUrl = trim((string) ($validated['file_url'] ?? ''));
            if ($fileUrl === '') {
                throw ValidationException::withMessages([
                    'file_url' => ['file_url cannot be empty.'],
                ]);
            }
            $payload['file_url'] = $fileUrl;
        }

        if (array_key_exists('thumbnail_url', $validated)) {
            $thumb = $validated['thumbnail_url'];
            $payload['thumbnail_url'] = $thumb === null || $thumb === ''
                ? null
                : trim((string) $thumb);
        }

        $duration = $request->resolvedDuration();
        if (array_key_exists('duration_seconds', $validated) || array_key_exists('duration', $validated)) {
            $payload['duration'] = $duration;
        }

        $tags = $request->resolvedTags();
        if ($tags !== null) {
            $payload['tags'] = $tags;
        }

        if (array_key_exists('is_active', $validated)) {
            $payload['is_active'] = (bool) $validated['is_active'];
        }

        if ($payload !== []) {
            $sound->update($payload);
        }

        return new SoundResource($sound->fresh());
    }

    public function destroy(Sound $sound): JsonResponse
    {
        $sound->delete();

        return response()->json(['message' => 'Sound deleted.']);
    }
}
