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

    public function soundThumbnail(int|string $soundId, string $extension): string
    {
        $ext = ltrim($extension, '.');

        return sprintf('sounds/%s/thumbnail/thumbnail.%s', $soundId, $ext);
    }

    public function coverThumbnail(int|string $coverBundleId, string $extension): string
    {
        $ext = ltrim($extension, '.');

        return sprintf('covers/%s/thumbnail/thumbnail.%s', $coverBundleId, $ext);
    }

    public function coverArtwork(int|string $coverBundleId, string $extension): string
    {
        $ext = ltrim($extension, '.');

        return sprintf('covers/%s/artwork/artwork.%s', $coverBundleId, $ext);
    }

    public function coverPlayerBackground(int|string $coverBundleId, string $extension): string
    {
        $ext = ltrim($extension, '.');

        return sprintf('covers/%s/player-background/background.%s', $coverBundleId, $ext);
    }

    public function vibeThumbnail(int|string $vibeId, string $extension): string
    {
        $ext = ltrim($extension, '.');

        return sprintf('vibes/%s/thumbnail/thumbnail.%s', $vibeId, $ext);
    }

    public function vibeArtwork(int|string $vibeId, string $extension): string
    {
        $ext = ltrim($extension, '.');

        return sprintf('vibes/%s/artwork/artwork.%s', $vibeId, $ext);
    }

    public function vibePlayerBackground(int|string $vibeId, string $extension): string
    {
        $ext = ltrim($extension, '.');

        return sprintf('vibes/%s/player-background/background.%s', $vibeId, $ext);
    }

    public function userAvatar(int|string $userId, string $extension): string
    {
        $ext = ltrim($extension, '.');

        return sprintf('users/%s/avatar/avatar.%s', $userId, $ext);
    }
}
