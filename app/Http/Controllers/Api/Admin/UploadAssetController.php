<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadAssetRequest;
use App\Services\Storage\DigitalOceanSpacesService;
use App\Services\Storage\StoragePathBuilder;
use App\Services\Storage\UploadAssetValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class UploadAssetController extends Controller
{
    public function store(
        UploadAssetRequest $request,
        DigitalOceanSpacesService $spaces,
        StoragePathBuilder $paths,
    ): JsonResponse {
        $entityType = (string) $request->validated('entity_type');
        $assetType = (string) $request->validated('asset_type');
        $entityId = $request->validated('entity_id');

        $file = $request->file('file');
        $extension = UploadAssetValidator::resolveExtension($file, $entityType, $assetType);
        if ($extension === null) {
            throw ValidationException::withMessages(['file' => ['Invalid file type.']]);
        }

        $key = $this->resolveObjectKey($paths, $entityType, $assetType, $entityId, $extension);

        $spaces->putFile($key, $file);

        return response()->json([
            'data' => [
                'key' => $key,
                'url' => $spaces->publicUrl($key),
                'entity_type' => $entityType,
                'asset_type' => $assetType,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ],
        ], 201);
    }

    private function resolveObjectKey(
        StoragePathBuilder $paths,
        string $entityType,
        string $assetType,
        int|string $entityId,
        string $extension,
    ): string {
        return match ($entityType) {
            'sound' => match ($assetType) {
                'audio' => $paths->soundAudio($entityId, $extension),
                'thumbnail' => $paths->soundThumbnail($entityId, $extension),
                default => throw new \InvalidArgumentException('Invalid asset type for sound.'),
            },
            'cover' => match ($assetType) {
                'thumbnail' => $paths->coverThumbnail($entityId, $extension),
                'artwork' => $paths->coverArtwork($entityId, $extension),
                'player_background' => $paths->coverPlayerBackground($entityId, $extension),
                default => throw new \InvalidArgumentException('Invalid asset type for cover.'),
            },
            'vibe' => match ($assetType) {
                'thumbnail' => $paths->vibeThumbnail($entityId, $extension),
                'artwork' => $paths->vibeArtwork($entityId, $extension),
                'player_background' => $paths->vibePlayerBackground($entityId, $extension),
                default => throw new \InvalidArgumentException('Invalid asset type for vibe.'),
            },
            'user' => match ($assetType) {
                'avatar' => $paths->userAvatar($entityId, $extension),
                default => throw new \InvalidArgumentException('Invalid asset type for user.'),
            },
            default => throw new \InvalidArgumentException('Invalid entity type.'),
        };
    }
}
