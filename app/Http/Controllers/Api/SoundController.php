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
        $fileUrl = $request->resolvedFileUrl();
        if ($fileUrl === '') {
            throw ValidationException::withMessages([
                'audio_url' => ['A file URL is required (file_url or audio_url).'],
            ]);
        }

        $validated = $request->validated();

        $sound = Sound::query()->create([
            'name' => $validated['name'],
            'category' => $validated['category'],
            'file_url' => $fileUrl,
            'thumbnail_url' => $validated['thumbnail_url'] ?? null,
            'duration' => $request->resolvedDuration(),
        ]);

        return (new SoundResource($sound))->response()->setStatusCode(201);
    }

    public function update(UpdateSoundRequest $request, Sound $sound): SoundResource
    {
        $validated = $request->validated();
        $payload = [];

        foreach (['name', 'category', 'thumbnail_url'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        $fileUrl = $request->resolvedFileUrl();
        if ($fileUrl !== null) {
            if ($fileUrl === '') {
                throw ValidationException::withMessages([
                    'audio_url' => ['file_url and audio_url cannot be empty.'],
                ]);
            }
            $payload['file_url'] = $fileUrl;
        }

        if (array_key_exists('duration_seconds', $validated)) {
            $payload['duration'] = $validated['duration_seconds'] === null
                ? null
                : (int) $validated['duration_seconds'];
        } elseif (array_key_exists('duration', $validated)) {
            $payload['duration'] = $validated['duration'] === null
                ? null
                : (int) $validated['duration'];
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
