<?php

declare(strict_types=1);

namespace App\Actions\Sound;

use App\Models\Sound;
use App\Services\Storage\DigitalOceanSpacesService;
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
    ) {}

    /**
     * Persist a Sound, upload canonical audio + thumbnail to Spaces, assign CDN URLs.
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

        $audioExtension = UploadAssetValidator::resolveExtension($audioFile, 'sound', 'audio');
        $thumbExtension = UploadAssetValidator::resolveExtension($thumbnailFile, 'sound', 'thumbnail');

        if ($audioExtension === null || $thumbExtension === null) {
            throw new \RuntimeException('Resolved extension unexpectedly null after upload validation.');
        }

        /** @var list<string> $uploadedKeys */
        $uploadedKeys = [];

        try {
            return DB::transaction(function () use (
                $metadata,
                $audioFile,
                $thumbnailFile,
                $audioExtension,
                $thumbExtension,
                &$uploadedKeys,
            ): Sound {
                $sound = Sound::query()->create([
                    'name' => $metadata['name'],
                    'category' => $metadata['category'],
                    'file_url' => '',
                    'thumbnail_url' => null,
                    'duration' => $metadata['duration_seconds'],
                    'tags' => $metadata['tags'],
                    'is_active' => $metadata['is_active'],
                ]);

                $audioKey = $this->paths->soundAudio($sound->id, $audioExtension);
                $thumbKey = $this->paths->soundThumbnail($sound->id, $thumbExtension);

                $this->spaces->putFile($audioKey, $audioFile);
                $uploadedKeys[] = $audioKey;
                $this->spaces->putFile($thumbKey, $thumbnailFile);
                $uploadedKeys[] = $thumbKey;

                $sound->update([
                    'file_url' => $this->spaces->publicUrl($audioKey),
                    'thumbnail_url' => $this->spaces->publicUrl($thumbKey),
                ]);

                /** @var Sound $fresh */
                $fresh = $sound->fresh();

                return $fresh;
            });
        } catch (Throwable $e) {
            foreach ($uploadedKeys as $key) {
                $this->spaces->delete($key);
            }

            throw $e;
        }
    }
}
