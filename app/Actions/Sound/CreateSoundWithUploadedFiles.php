<?php

declare(strict_types=1);

namespace App\Actions\Sound;

use App\Models\Sound;
use App\Services\Audio\SoundAudioStatus;
use App\Services\Audio\SoundAudioSubmission;
use App\Services\Storage\DigitalOceanSpacesService;
use App\Services\Storage\StorageDeletionService;
use App\Services\Storage\StoragePathBuilder;
use App\Services\Storage\UploadAssetValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateSoundWithUploadedFiles
{
    public function __construct(
        private DigitalOceanSpacesService $spaces,
        private StoragePathBuilder $paths,
        private SoundAudioSubmission $audioSubmission,
        private StorageDeletionService $deletion,
    ) {}

    /**
     * Persist a Sound, publish its thumbnail, and enqueue the audio for asynchronous processing.
     *
     * The sound is created with `audio_status = pending` and an empty `file_url`: the audio only becomes
     * playable when the worker has transcoded, validated and published a versioned distribution asset.
     * If the audio cannot be enqueued the whole creation is rolled back so the admin can simply retry.
     *
     * @param  array{
     *     name: string,
     *     category: string,
     *     duration_seconds: int|null,
     *     tags: list<string>,
     *     is_active: bool
     * }  $metadata
     *
     * @throws Throwable
     */
    public function __invoke(array $metadata, UploadedFile $audioFile, UploadedFile $thumbnailFile): Sound
    {
        UploadAssetValidator::assertValidSoundAudio($audioFile);
        UploadAssetValidator::assertValidSoundThumbnail($thumbnailFile);

        $thumbExtension = UploadAssetValidator::resolveExtension($thumbnailFile, 'sound', 'thumbnail');
        if ($thumbExtension === null) {
            throw new \RuntimeException('Resolved extension unexpectedly null after upload validation.');
        }

        $sound = $this->createSoundWithThumbnail($metadata, $thumbnailFile, $thumbExtension);

        try {
            $this->audioSubmission->submit($sound, $audioFile);
        } catch (Throwable $e) {
            $this->rollback($sound);

            throw $e;
        }

        /** @var Sound $fresh */
        $fresh = $sound->fresh();

        return $fresh;
    }

    /**
     * @param  array{name: string, category: string, duration_seconds: int|null, tags: list<string>, is_active: bool}  $metadata
     */
    private function createSoundWithThumbnail(array $metadata, UploadedFile $thumbnailFile, string $thumbExtension): Sound
    {
        $thumbKey = null;

        try {
            return DB::transaction(function () use ($metadata, $thumbnailFile, $thumbExtension, &$thumbKey): Sound {
                $sound = Sound::query()->create([
                    'name' => $metadata['name'],
                    'category' => $metadata['category'],
                    'file_url' => '',
                    'audio_status' => SoundAudioStatus::Pending->value,
                    'thumbnail_url' => null,
                    'duration' => $metadata['duration_seconds'],
                    'tags' => $metadata['tags'],
                    'is_active' => $metadata['is_active'],
                ]);

                $thumbKey = $this->paths->soundThumbnail($sound->id, $thumbExtension);
                $this->spaces->putFile($thumbKey, $thumbnailFile);

                $sound->update(['thumbnail_url' => $this->spaces->publicUrl($thumbKey)]);

                return $sound;
            });
        } catch (Throwable $e) {
            if (is_string($thumbKey)) {
                $this->spaces->delete($thumbKey);
            }

            throw $e;
        }
    }

    /**
     * Undo a creation whose audio could not be enqueued. Deletions are recorded durably first, so a failure
     * while cleaning Spaces is retried instead of leaving orphans.
     */
    private function rollback(Sound $sound): void
    {
        DB::transaction(function () use ($sound): void {
            $this->deletion->scheduleForSound($sound);
            $sound->delete();
        });

        $this->deletion->runPending();
    }
}
