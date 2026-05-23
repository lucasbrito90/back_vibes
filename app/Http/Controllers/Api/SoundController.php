<?php

namespace App\Http\Controllers\Api;

use App\Actions\Sound\CreateSoundWithUploadedFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSoundRequest;
use App\Http\Requests\UpdateSoundRequest;
use App\Http\Resources\SoundResource;
use App\Models\Sound;
use App\Services\Storage\SafeAssetDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
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

    public function store(StoreSoundRequest $request, CreateSoundWithUploadedFiles $createSound): JsonResponse
    {
        $validated = $request->validated();
        /** @var \Illuminate\Http\UploadedFile|null $audio */
        $audio = $request->file('audio_file');
        /** @var \Illuminate\Http\UploadedFile|null $thumbnail */
        $thumbnail = $request->file('thumbnail_file');

        if (! $audio instanceof UploadedFile || ! $thumbnail instanceof UploadedFile) {
            throw ValidationException::withMessages([
                'audio_file' => ['A valid audio file is required.'],
                'thumbnail_file' => ['A valid thumbnail image is required.'],
            ]);
        }

        $metadata = [
            'name' => $validated['name'],
            'category' => $validated['category'],
            'duration_seconds' => $request->resolvedDuration(),
            'tags' => $request->resolvedTags(),
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : true,
        ];

        $sound = $createSound($metadata, $audio, $thumbnail);

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

    public function destroy(Sound $sound, SafeAssetDeletionService $safeAssetDeletion): JsonResponse
    {
        if ($this->soundIsUsedOnAnyVibe($sound)) {
            Log::warning('Sound delete blocked: sound is attached to one or more vibes', ['sound_id' => $sound->id]);

            return response()->json([
                'message' => 'This sound is currently used by one or more vibes and cannot be deleted.',
            ], 409);
        }

        $urls = [];
        foreach ([$sound->file_url, $sound->thumbnail_url] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                $urls[] = trim($candidate);
            }
        }

        DB::transaction(static fn () => $sound->delete());

        foreach ($safeAssetDeletion->deleteUrlsIfUnreferenced($urls) as $url => $status) {
            if ($status === SafeAssetDeletionService::STATUS_FAILED) {
                Log::warning('Sound deleted from DB but Spaces object cleanup failed', [
                    'url' => $url,
                    'status' => $status,
                ]);
            }
        }

        return response()->json(['message' => 'Sound deleted.']);
    }

    private function soundIsUsedOnAnyVibe(Sound $sound): bool
    {
        if (Schema::hasTable('vibe_sounds') && DB::table('vibe_sounds')->where('sound_id', $sound->id)->exists()) {
            return true;
        }

        return Schema::hasTable('preset_vibe_sounds')
            && DB::table('preset_vibe_sounds')->where('sound_id', $sound->id)->exists();
    }
}
