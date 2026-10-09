<?php

namespace App\Http\Controllers\Api;

use App\Actions\Sound\CreateSoundWithUploadedFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\IndexSoundRequest;
use App\Http\Requests\ReplaceSoundAudioRequest;
use App\Http\Requests\StoreSoundRequest;
use App\Http\Requests\UpdateSoundRequest;
use App\Http\Resources\SoundResource;
use App\Jobs\Storage\PurgeStorageDeletionTasks;
use App\Models\Sound;
use App\Queries\SoundCatalogQuery;
use App\Services\Audio\AudioDispatchException;
use App\Services\Audio\SoundAudioSubmission;
use App\Services\Storage\StorageDeletionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SoundController extends Controller
{
    use AuthorizesRequests;

    public function index(IndexSoundRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Sound::class);

        $catalog = new SoundCatalogQuery($request, $request->user());
        $query = $catalog->build()->with('latestAudioRevision');

        if ($catalog->wantsPagination()) {
            $paginator = $query->paginate($catalog->perPage());

            return SoundResource::collection($paginator)->additional([
                'available_categories' => $catalog->availableCategories(),
            ]);
        }

        return SoundResource::collection($query->get());
    }

    public function show(Sound $sound): SoundResource
    {
        $this->authorize('view', $sound);

        return new SoundResource($sound->load('latestAudioRevision'));
    }

    public function store(StoreSoundRequest $request, CreateSoundWithUploadedFiles $createSound): JsonResponse
    {
        $validated = $request->validated();
        /** @var UploadedFile|null $audio */
        $audio = $request->file('audio_file');
        /** @var UploadedFile|null $thumbnail */
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

        try {
            $sound = $createSound($metadata, $audio, $thumbnail);
        } catch (AudioDispatchException $e) {
            return $this->audioNotEnqueued($e);
        }

        return (new SoundResource($sound->load('latestAudioRevision')))->response()->setStatusCode(201);
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

        // `file_url` is derived from the published audio version. Clients may still echo the current value
        // (the admin form does) or send null, but can never change it here: new audio goes through
        // POST /api/admin/sounds/{sound}/audio so published bytes/URLs are never overwritten.
        if (array_key_exists('file_url', $validated)) {
            $fileUrl = trim((string) ($validated['file_url'] ?? ''));
            if ($fileUrl !== '' && $fileUrl !== trim((string) $sound->file_url)) {
                throw ValidationException::withMessages([
                    'file_url' => ['file_url cannot be changed directly. Upload a new audio file instead.'],
                ]);
            }
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

        return new SoundResource($sound->fresh()->load('latestAudioRevision'));
    }

    public function replaceAudio(
        ReplaceSoundAudioRequest $request,
        Sound $sound,
        SoundAudioSubmission $submission,
    ): JsonResponse {
        /** @var UploadedFile $audio */
        $audio = $request->file('audio_file');

        try {
            $submission->submit($sound, $audio);
        } catch (AudioDispatchException $e) {
            return $this->audioNotEnqueued($e);
        }

        return (new SoundResource($sound->fresh()->load('latestAudioRevision')))
            ->response()
            ->setStatusCode(202);
    }

    public function destroy(Sound $sound, StorageDeletionService $deletion): JsonResponse
    {
        if ($this->soundIsUsedOnAnyVibe($sound)) {
            Log::warning('Sound delete blocked: sound is attached to one or more vibes', ['sound_id' => $sound->id]);

            return response()->json([
                'message' => 'This sound is currently used by one or more vibes and cannot be deleted.',
            ], 409);
        }

        // Record what must disappear from Spaces in the same transaction that removes the row, so a crash
        // (or Spaces outage) after commit can never lose track of the objects: pending tasks are retried.
        /** @var list<int> $taskIds */
        $taskIds = DB::transaction(function () use ($sound, $deletion): array {
            $ids = array_map(static fn ($task): int => $task->id, $deletion->scheduleForSound($sound));
            $sound->delete();

            return $ids;
        });

        $deletion->runPending();

        if ($deletion->hasPending($taskIds)) {
            Log::warning('Sound deleted from DB but Spaces cleanup is still pending', ['sound_id' => $sound->id]);
            PurgeStorageDeletionTasks::dispatch()->delay(now()->addMinute());

            return response()->json([
                'message' => 'Sound deleted. Storage cleanup is pending and will be retried automatically.',
                'storage_cleanup' => 'pending',
            ]);
        }

        return response()->json(['message' => 'Sound deleted.', 'storage_cleanup' => 'completed']);
    }

    private function audioNotEnqueued(AudioDispatchException $e): JsonResponse
    {
        Log::error('Audio processing could not be enqueued', ['message' => $e->getMessage()]);

        return response()->json([
            'message' => 'Audio processing could not be enqueued. Nothing was published; please try again.',
            'code' => 'audio_not_enqueued',
        ], 503);
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
