<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Validator;

final class UploadAssetValidator
{
    public const AUDIO_MAX_BYTES = 26214400; // 25 MiB

    public const IMAGE_MAX_BYTES = 5242880; // 5 MiB

    /** @var array<string, string> lowercase MIME => canonical extension (no dot) */
    private const AUDIO_MIME_TO_EXT = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/wave' => 'wav',
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac',
        'audio/x-aac' => 'aac',
    ];

    /** @var array<string, string> lowercase MIME => canonical extension (no dot) */
    private const IMAGE_MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @return list<string>
     */
    public static function assetTypesForEntity(string $entityType): array
    {
        return match ($entityType) {
            'sound' => ['audio', 'thumbnail'],
            'cover' => ['thumbnail', 'artwork', 'player_background'],
            'vibe' => ['thumbnail', 'artwork', 'player_background'],
            'user' => ['avatar'],
            default => [],
        };
    }

    public static function expectsAudio(string $entityType, string $assetType): bool
    {
        return $entityType === 'sound' && $assetType === 'audio';
    }

    public static function maxBytes(string $entityType, string $assetType): int
    {
        return self::expectsAudio($entityType, $assetType)
            ? self::AUDIO_MAX_BYTES
            : self::IMAGE_MAX_BYTES;
    }

    public static function resolveExtension(UploadedFile $file, string $entityType, string $assetType): ?string
    {
        $mime = strtolower((string) $file->getMimeType());

        if (self::expectsAudio($entityType, $assetType)) {
            return self::AUDIO_MIME_TO_EXT[$mime] ?? null;
        }

        return self::IMAGE_MIME_TO_EXT[$mime] ?? null;
    }

    public static function validateAfterBaseRules(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        /** @var array<string, mixed> $data */
        $data = $validator->getData();
        $entityType = (string) ($data['entity_type'] ?? '');
        $assetType = (string) ($data['asset_type'] ?? '');
        $entityId = $data['entity_id'] ?? null;

        $entityTable = self::tableForEntityType($entityType);
        if ($entityTable === null) {
            return;
        }

        $exists = ValidatorFacade::make(
            ['entity_id' => $entityId],
            ['entity_id' => ['required', 'integer', 'exists:'.$entityTable.',id']],
        );

        if ($exists->fails()) {
            $validator->errors()->add('entity_id', 'The selected entity does not exist.');

            return;
        }

        $allowed = self::assetTypesForEntity($entityType);
        if (! in_array($assetType, $allowed, true)) {
            $validator->errors()->add('asset_type', 'The selected asset type is invalid for this entity.');

            return;
        }

        $file = request()->file('file');
        if (! $file instanceof UploadedFile) {
            $validator->errors()->add('file', 'A valid file is required.');

            return;
        }

        if (! $file->isValid()) {
            $validator->errors()->add('file', 'The upload failed.');

            return;
        }

        if ($file->getSize() > self::maxBytes($entityType, $assetType)) {
            $validator->errors()->add(
                'file',
                self::expectsAudio($entityType, $assetType)
                    ? 'Audio must not exceed 25 MB.'
                    : 'Image must not exceed 5 MB.',
            );

            return;
        }

        if (self::resolveExtension($file, $entityType, $assetType) === null) {
            $validator->errors()->add(
                'file',
                self::expectsAudio($entityType, $assetType)
                    ? 'Invalid audio type. Allowed: MP3, OGG, WAV, M4A, AAC.'
                    : 'Invalid image type. Allowed: JPEG, PNG, WebP.',
            );
        }
    }

    private static function tableForEntityType(string $entityType): ?string
    {
        return match ($entityType) {
            'sound' => 'sounds',
            'cover' => 'cover_bundles',
            'vibe' => 'vibes',
            'user' => 'users',
            default => null,
        };
    }
}
