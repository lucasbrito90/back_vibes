<?php

declare(strict_types=1);

use App\Models\CoverBundle;
use App\Models\PresetVibe;
use App\Models\User;
use App\Models\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'filesystems.disks.spaces.throw' => false,
        'filesystems.disks.spaces.url' => 'https://ixora-buckets.tor1.cdn.digitaloceanspaces.com',
        'filesystems.disks.spaces.bucket' => 'ixora-buckets',
        'filesystems.disks.spaces.region' => 'tor1',
        'filesystems.disks.spaces.endpoint' => 'https://tor1.digitaloceanspaces.com',
    ]);
    Storage::fake('spaces');
});

function jwtForCoverBundleSafeDeletion(User $user): UnencryptedToken
{
    $dataset = new DataSet([
        'sub' => $user->firebase_uid,
        'email' => $user->email,
        'name' => $user->name,
    ], 'e30.');
    $jwt = Mockery::mock(UnencryptedToken::class);
    $jwt->shouldReceive('claims')->andReturn($dataset);

    return $jwt;
}

/** @param  non-empty-string  $objectKey */
function coverBundlePublicUrlForKey(string $objectKey): string
{
    return rtrim((string) config('filesystems.disks.spaces.url'), '/').'/'.ltrim($objectKey, '/');
}

test('approved admin deletes unused cover bundle and Spaces objects are removed', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-cb-safe-del-admin',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $thumbKey = 'covers/501/thumbnail/thumbnail.webp';
    $artKey = 'covers/501/artwork/artwork.webp';
    $bgKey = 'covers/501/player-background/bg.webp';
    Storage::disk('spaces')->put($thumbKey, 't');
    Storage::disk('spaces')->put($artKey, 'a');
    Storage::disk('spaces')->put($bgKey, 'b');

    $bundle = CoverBundle::query()->create([
        'name' => 'Unused pack',
        'description' => null,
        'thumbnail_url' => coverBundlePublicUrlForKey($thumbKey),
        'artwork_url' => coverBundlePublicUrlForKey($artKey),
        'player_background_url' => coverBundlePublicUrlForKey($bgKey),
        'category' => null,
        'tags' => [],
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleSafeDeletion($user)));

    $this->deleteJson("/api/cover-bundles/{$bundle->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJson(['message' => 'Cover bundle deleted.']);

    expect(CoverBundle::query()->find($bundle->id))->toBeNull();
    Storage::disk('spaces')->assertMissing($thumbKey);
    Storage::disk('spaces')->assertMissing($artKey);
    Storage::disk('spaces')->assertMissing($bgKey);
});

test('DELETE cover bundle returns 409 when referenced by a preset vibe', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-cb-409-preset',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $bundle = CoverBundle::query()->create([
        'name' => 'Preset pack',
        'thumbnail_url' => coverBundlePublicUrlForKey('covers/9/t.webp'),
        'tags' => [],
        'is_active' => true,
    ]);

    PresetVibe::query()->create([
        'name' => 'Uses bundle',
        'description' => null,
        'cover_bundle_id' => $bundle->id,
        'category' => null,
        'tags' => [],
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleSafeDeletion($user)));

    $this->deleteJson("/api/cover-bundles/{$bundle->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertStatus(409)
        ->assertJson([
            'message' => 'This cover bundle is currently used by one or more vibes and cannot be deleted.',
        ]);

    expect(CoverBundle::query()->find($bundle->id))->not->toBeNull();
});

test('DELETE cover bundle returns 409 when bundle URLs are copied on user vibes', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-cb-409-vibe',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $thumbUrl = coverBundlePublicUrlForKey('covers/shared/on-vibe/th.webp');

    $bundle = CoverBundle::query()->create([
        'name' => 'Copied URLs',
        'thumbnail_url' => $thumbUrl,
        'tags' => [],
        'is_active' => true,
    ]);

    Vibe::query()->create([
        'user_id' => $user->id,
        'name' => 'My vibe',
        'description' => null,
        'thumbnail_url' => $thumbUrl,
        'artwork_url' => null,
        'player_background_url' => null,
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleSafeDeletion($user)));

    $this->deleteJson("/api/cover-bundles/{$bundle->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertStatus(409)
        ->assertJson([
            'message' => 'This cover bundle is currently used by one or more vibes and cannot be deleted.',
        ]);

    expect(CoverBundle::query()->find($bundle->id))->not->toBeNull();
});

test('shared artwork URL with another cover bundle is not deleted from Spaces', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-cb-shared-art',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $artKey = 'covers/shared/art/a.webp';
    $artUrl = coverBundlePublicUrlForKey($artKey);
    Storage::disk('spaces')->put($artKey, 'art');

    $bundleA = CoverBundle::query()->create([
        'name' => 'A',
        'artwork_url' => $artUrl,
        'tags' => [],
        'is_active' => true,
    ]);

    CoverBundle::query()->create([
        'name' => 'B',
        'artwork_url' => $artUrl,
        'tags' => [],
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleSafeDeletion($user)));

    $this->deleteJson("/api/cover-bundles/{$bundleA->id}", [], ['Authorization' => 'Bearer tok'])->assertOk();

    Storage::disk('spaces')->assertExists($artKey);
});

test('external URLs are ignored for Spaces cleanup and cover bundle row is deleted', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-cb-external',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $bundle = CoverBundle::query()->create([
        'name' => 'Legacy CDN',
        'thumbnail_url' => 'https://firebasestorage.googleapis.com/v0/b/bucket/o/t.webp?alt=media',
        'tags' => [],
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleSafeDeletion($user)));

    $this->deleteJson("/api/cover-bundles/{$bundle->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertOk();

    expect(CoverBundle::query()->find($bundle->id))->toBeNull();
});

test('regular user cannot DELETE /api/cover-bundles/{coverBundle}', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-cb-no-del',
        'role' => 'user',
        'admin_access_status' => 'none',
    ]);

    $bundle = CoverBundle::query()->create([
        'name' => 'Protected',
        'tags' => [],
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleSafeDeletion($user)));

    $this->deleteJson("/api/cover-bundles/{$bundle->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);

    expect(CoverBundle::query()->find($bundle->id))->not->toBeNull();
});
