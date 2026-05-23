<?php

declare(strict_types=1);

namespace App\Actions\CoverBundle;

use App\Models\CoverBundle;
use App\Services\Storage\DigitalOceanSpacesService;
use App\Services\Storage\StoragePathBuilder;
use App\Services\Storage\UploadAssetValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateCoverBundleWithUploadedFiles
{
    public function __construct(
        private DigitalOceanSpacesService $spaces,
        private StoragePathBuilder $paths,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     description: ?string,
     *     category: string,
     *     tags: list<string>,
     *     is_active: bool
     * }  $metadata
     *
     * @throws Throwable
     */
    public function __invoke(
        array $metadata,
        UploadedFile $thumbnailFile,
        UploadedFile $artworkFile,
        UploadedFile $playerBackgroundFile,
    ): CoverBundle {
        UploadAssetValidator::assertValidCoverCatalogImage($thumbnailFile, 'thumbnail');
        UploadAssetValidator::assertValidCoverCatalogImage($artworkFile, 'artwork');
        UploadAssetValidator::assertValidCoverCatalogImage($playerBackgroundFile, 'player_background');

        $thumbExtension = UploadAssetValidator::resolveExtension($thumbnailFile, 'cover', 'thumbnail');
        $artExtension = UploadAssetValidator::resolveExtension($artworkFile, 'cover', 'artwork');
        $bgExtension = UploadAssetValidator::resolveExtension($playerBackgroundFile, 'cover', 'player_background');

        if ($thumbExtension === null || $artExtension === null || $bgExtension === null) {
            throw new \RuntimeException('Resolved extension unexpectedly null after upload validation.');
        }

        /** @var list<string> $uploadedKeys */
        $uploadedKeys = [];

        try {
            return DB::transaction(function () use (
                $metadata,
                $thumbnailFile,
                $artworkFile,
                $playerBackgroundFile,
                $thumbExtension,
                $artExtension,
                $bgExtension,
                &$uploadedKeys,
            ): CoverBundle {
                $bundle = CoverBundle::query()->create([
                    'name' => $metadata['name'],
                    'description' => $metadata['description'],
                    'thumbnail_url' => null,
                    'artwork_url' => null,
                    'player_background_url' => null,
                    'category' => $metadata['category'],
                    'tags' => $metadata['tags'],
                    'is_active' => $metadata['is_active'],
                ]);

                $thumbKey = $this->paths->coverThumbnail($bundle->id, $thumbExtension);
                $artKey = $this->paths->coverArtwork($bundle->id, $artExtension);
                $bgKey = $this->paths->coverPlayerBackground($bundle->id, $bgExtension);

                $this->spaces->putFile($thumbKey, $thumbnailFile);
                $uploadedKeys[] = $thumbKey;
                $this->spaces->putFile($artKey, $artworkFile);
                $uploadedKeys[] = $artKey;
                $this->spaces->putFile($bgKey, $playerBackgroundFile);
                $uploadedKeys[] = $bgKey;

                $bundle->update([
                    'thumbnail_url' => $this->spaces->publicUrl($thumbKey),
                    'artwork_url' => $this->spaces->publicUrl($artKey),
                    'player_background_url' => $this->spaces->publicUrl($bgKey),
                ]);

                /** @var CoverBundle $fresh */
                $fresh = $bundle->fresh();

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
