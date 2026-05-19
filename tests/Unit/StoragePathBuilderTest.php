<?php

declare(strict_types=1);

use App\Services\Storage\StoragePathBuilder;
use Tests\TestCase;

uses(TestCase::class);

test('StoragePathBuilder returns expected canonical keys', function (): void {
    $b = new StoragePathBuilder;

    expect($b->soundAudio(5, 'mp3'))->toBe('sounds/5/audio/original.mp3')
        ->and($b->soundAudio('12', '.wav'))->toBe('sounds/12/audio/original.wav')
        ->and($b->soundThumbnail(7))->toBe('sounds/7/thumbnail/thumbnail.webp')
        ->and($b->coverThumbnail(3))->toBe('covers/3/thumbnail/thumbnail.webp')
        ->and($b->coverArtwork(3))->toBe('covers/3/artwork/artwork.webp')
        ->and($b->coverPlayerBackground(3))->toBe('covers/3/player-background/background.webp')
        ->and($b->vibeThumbnail(9))->toBe('vibes/9/thumbnail/thumbnail.webp')
        ->and($b->vibeArtwork(9))->toBe('vibes/9/artwork/artwork.webp')
        ->and($b->vibePlayerBackground(9))->toBe('vibes/9/player-background/background.webp')
        ->and($b->userAvatar(2))->toBe('users/2/avatar/avatar.webp');
});
