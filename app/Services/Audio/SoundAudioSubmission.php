<?php

declare(strict_types=1);

namespace App\Services\Audio;

use App\Models\Sound;
use App\Models\SoundAudioRevision;
use App\Services\Storage\DigitalOceanSpacesService;
use App\Services\Storage\StoragePathBuilder;
use App\Services\Storage\UploadAssetValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Single entry point for new audio: an admin upload, or an already stored object being reprocessed.
 *
 * It stores the audio as a *private source*, reserves a version, persists a queued revision and hands it
 * to the queue. It never touches `file_url` / `audio_version`: only the processor can publish.
 */
final class SoundAudioSubmission
{
    public const ORIGIN_UPLOAD = 'upload';

    public const ORIGIN_REPROCESS = 'reprocess';

    public function __construct(
        private readonly DigitalOceanSpacesService $spaces,
        private readonly StoragePathBuilder $paths,
        private readonly SoundAudioRevisionDispatcher $dispatcher,
    ) {}

    /**
     * @throws ValidationException invalid upload
     * @throws AudioDispatchException when the job could not be enqueued (the revision is marked failed and its source removed)
     */
    public function submit(Sound $sound, UploadedFile $audio): SoundAudioRevision
    {
        UploadAssetValidator::assertValidSoundAudio($audio);
        $extension = UploadAssetValidator::resolveExtension($audio, 'sound', 'audio');
        if ($extension === null) {
            throw new \RuntimeException('Resolved extension unexpectedly null after upload validation.');
        }

        $version = $this->reserveVersion($sound);
        $sourceKey = $this->paths->soundAudioSource($sound->id, $version, $extension);

        $this->spaces->putFile($sourceKey, $audio, 'private');

        try {
            $revision = SoundAudioRevision::query()->create([
                'sound_id' => $sound->id,
                'version' => $version,
                'status' => AudioRevisionStatus::Queued->value,
                'origin' => self::ORIGIN_UPLOAD,
                'source_key' => $sourceKey,
                'source_mime' => $audio->getMimeType(),
                'source_bytes' => $audio->getSize(),
                'queued_at' => now(),
            ]);
        } catch (Throwable $e) {
            $this->spaces->delete($sourceKey);

            throw $e;
        }

        return $this->dispatchOrFail($revision);
    }

    /**
     * Reprocess audio that is already in Spaces (the currently published object) through the same pipeline.
     * The existing object is copied to a new private source; nothing existing is modified or deleted.
     *
     * The idle check, the version reservation, the copy and the revision insert all happen in ONE transaction
     * under the sound's row lock. Releasing the lock before the revision exists would let a concurrent run pass
     * the idle check too (observed with parallel runs on PostgreSQL). Concurrent callers block on the lock, then
     * see the in-flight revision and get null, without side effects.
     *
     * @throws AudioDispatchException
     */
    public function submitStoredObject(Sound $sound, string $existingKey): ?SoundAudioRevision
    {
        $sourceKey = null;

        try {
            $revision = DB::transaction(function () use ($sound, $existingKey, &$sourceKey): ?SoundAudioRevision {
                /** @var Sound $locked */
                $locked = Sound::query()->whereKey($sound->id)->lockForUpdate()->firstOrFail();

                $busy = $locked->audioRevisions()
                    ->whereIn('status', array_map(
                        static fn (AudioRevisionStatus $s): string => $s->value,
                        AudioRevisionStatus::inFlight(),
                    ))
                    ->exists();
                if ($busy) {
                    return null;
                }

                $version = ((int) $locked->audio_last_reserved_version) + 1;
                $locked->forceFill(['audio_last_reserved_version' => $version])->save();

                $extension = strtolower(pathinfo($existingKey, PATHINFO_EXTENSION)) ?: 'bin';
                $sourceKey = $this->paths->soundAudioSource($locked->id, $version, $extension);

                $this->spaces->copyObject($existingKey, $sourceKey, 'private');

                return SoundAudioRevision::query()->create([
                    'sound_id' => $locked->id,
                    'version' => $version,
                    'status' => AudioRevisionStatus::Queued->value,
                    'origin' => self::ORIGIN_REPROCESS,
                    'source_key' => $sourceKey,
                    'source_bytes' => $this->spaces->size($sourceKey),
                    'queued_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            // The transaction rolled back (version reservation included); drop a partially copied source.
            if ($sourceKey !== null) {
                $this->spaces->delete($sourceKey);
            }

            throw $e;
        }

        return $revision === null ? null : $this->dispatchOrFail($revision);
    }

    private function dispatchOrFail(SoundAudioRevision $revision): SoundAudioRevision
    {
        try {
            $this->dispatcher->dispatch($revision);
        } catch (AudioDispatchException $e) {
            $revision->forceFill([
                'status' => AudioRevisionStatus::Failed->value,
                'error_code' => 'dispatch_failed',
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
                'finished_at' => now(),
            ])->save();
            $this->spaces->delete($revision->source_key);

            throw $e;
        }

        return $revision->refresh();
    }

    /**
     * Monotonic and gap-tolerant: a failed attempt keeps its number, so no version is ever reused.
     */
    private function reserveVersion(Sound $sound): int
    {
        return DB::transaction(function () use ($sound): int {
            /** @var Sound $locked */
            $locked = Sound::query()->whereKey($sound->id)->lockForUpdate()->firstOrFail();
            $next = ((int) $locked->audio_last_reserved_version) + 1;
            $locked->forceFill(['audio_last_reserved_version' => $next])->save();

            return $next;
        });
    }
}
