<?php

declare(strict_types=1);

use App\Models\CoverBundle;
use App\Models\Sound;
use App\Models\User;
use App\Models\Vibe;
use App\Services\Storage\StorageAssetReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

test('countReferencesToUrl sums matching rows in existing tables', function (): void {
    $url = 'https://ixora-buckets.tor1.cdn.digitaloceanspaces.com/refs/a.webp';

    $user = User::factory()->create(['avatar_url' => $url]);

    Sound::query()->create([
        'name' => 'One',
        'file_url' => $url,
        'thumbnail_url' => null,
        'category' => 'ambience',
        'duration' => null,
        'tags' => [],
        'is_active' => true,
    ]);

    CoverBundle::query()->create([
        'name' => 'Pack',
        'description' => null,
        'thumbnail_url' => $url,
        'artwork_url' => null,
        'player_background_url' => null,
        'category' => null,
        'tags' => [],
        'is_active' => true,
    ]);

    Vibe::query()->create([
        'user_id' => $user->id,
        'name' => 'V',
        'description' => null,
        'thumbnail_url' => null,
        'card_image_url' => null,
        'player_background_url' => null,
        'artwork_url' => $url,
        'is_active' => true,
    ]);

    $svc = new StorageAssetReferenceService;

    expect($svc->countReferencesToUrl($url))->toBe(4);
});
