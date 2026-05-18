<?php

declare(strict_types=1);

namespace App\Services\Storage;

final class StoragePathBuilder
{
    public function soundAudio(int|string $soundId, string $extension): string
    {
        $ext = ltrim($extension, '.');

        return sprintf('sounds/%s/audio/original.%s', $soundId, $ext);
    }

    public function soundThumbnail(int|string $soundId): string
    {
        return sprintf('sounds/%s/thumbnail/thumbnail.webp', $soundId);
    }

    public function coverThumbnail(int|string $coverBundleId): string
    {
        return sprintf('covers/%s/thumbnail/thumbnail.webp', $coverBundleId);
    }

    public function coverArtwork(int|string $coverBundleId): string
    {
        return sprintf('covers/%s/artwork/artwork.webp', $coverBundleId);
    }

    public function coverPlayerBackground(int|string $coverBundleId): string
    {
        return sprintf('covers/%s/player-background/background.webp', $coverBundleId);
    }

    public function vibeThumbnail(int|string $vibeId): string
    {
        return sprintf('vibes/%s/thumbnail/thumbnail.webp', $vibeId);
    }

    public function vibeArtwork(int|string $vibeId): string
    {
        return sprintf('vibes/%s/artwork/artwork.webp', $vibeId);
    }

    public function vibePlayerBackground(int|string $vibeId): string
    {
        return sprintf('vibes/%s/player-background/background.webp', $vibeId);
    }

    public function userAvatar(int|string $userId): string
    {
        return sprintf('users/%s/avatar/avatar.webp', $userId);
    }
}
