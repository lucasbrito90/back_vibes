<?php

declare(strict_types=1);

use App\Models\PresetVibe;
use App\Models\Sound;
use App\Models\User;
use App\Models\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

/** @param  non-empty-string  $sub */
function jwtForSoundSafeDeletion(User $user): UnencryptedToken
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
function cdnUrlForSpacesKey(string $objectKey): string
{
    return rtrim((string) config('filesystems.disks.spaces.url'), '/').'/'.ltrim($objectKey, '/');
}

test('approved admin deletes unused sound and Spaces objects are removed', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-safe-del-admin',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $audioKey = 'sounds/101/audio/original.mp3';
    $thumbKey = 'sounds/101/thumbnail/thumb.webp';
    Storage::disk('spaces')->put($audioKey, 'audio-bytes');
    Storage::disk('spaces')->put($thumbKey, 'thumb-bytes');

    $sound = Sound::query()->create([
        'name' => 'Unused',
        'file_url' => cdnUrlForSpacesKey($audioKey),
        'thumbnail_url' => cdnUrlForSpacesKey($thumbKey),
        'category' => 'Test',
        'duration' => 10,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundSafeDeletion($user)));

    $this->deleteJson("/api/sounds/{$sound->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJson(['message' => 'Sound deleted.']);

    expect(Sound::query()->find($sound->id))->toBeNull();
    Storage::disk('spaces')->assertMissing($audioKey);
    Storage::disk('spaces')->assertMissing($thumbKey);
});

test('DELETE sound returns 409 when sound is attached to a user vibe', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-safe-del-409-vibe',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $sound = Sound::query()->create([
        'name' => 'In use',
        'file_url' => cdnUrlForSpacesKey('sounds/202/audio/x.mp3'),
        'category' => 'Test',
        'duration' => 1,
    ]);

    $vibe = Vibe::query()->create([
        'user_id' => $user->id,
        'name' => 'My vibe',
        'description' => null,
        'is_active' => true,
    ]);

    DB::table('vibe_sounds')->insert([
        'vibe_id' => $vibe->id,
        'sound_id' => $sound->id,
        'volume' => 80,
        'loop' => true,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundSafeDeletion($user)));

    $this->deleteJson("/api/sounds/{$sound->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertStatus(409)
        ->assertJson([
            'message' => 'This sound is currently used by one or more vibes and cannot be deleted.',
        ]);

    expect(Sound::query()->find($sound->id))->not->toBeNull();
});

test('DELETE sound returns 409 when sound is attached to a preset vibe', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-safe-del-409-preset',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $sound = Sound::query()->create([
        'name' => 'Preset layer',
        'file_url' => cdnUrlForSpacesKey('sounds/303/audio/y.mp3'),
        'category' => 'Test',
        'duration' => 1,
    ]);

    $preset = PresetVibe::query()->create([
        'name' => 'Preset',
        'description' => null,
        'cover_bundle_id' => null,
        'category' => null,
        'tags' => [],
        'is_active' => true,
    ]);

    DB::table('preset_vibe_sounds')->insert([
        'preset_vibe_id' => $preset->id,
        'sound_id' => $sound->id,
        'volume' => 80,
        'loop' => true,
        'play_mode' => 'loop',
        'sort_order' => 0,
        'repeat_interval_seconds' => null,
        'start_offset_seconds' => null,
        'play_duration_seconds' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundSafeDeletion($user)));

    $this->deleteJson("/api/sounds/{$sound->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertStatus(409)
        ->assertJson([
            'message' => 'This sound is currently used by one or more vibes and cannot be deleted.',
        ]);

    expect(Sound::query()->find($sound->id))->not->toBeNull();
});

test('shared thumbnail URL is not deleted from Spaces when another sound still references it', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-shared-thumb',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $sharedThumbKey = 'sounds/shared/thumb.webp';
    $sharedThumbUrl = cdnUrlForSpacesKey($sharedThumbKey);
    Storage::disk('spaces')->put($sharedThumbKey, 'shared-thumb');

    $soundA = Sound::query()->create([
        'name' => 'A',
        'file_url' => cdnUrlForSpacesKey('sounds/a/audio/a.mp3'),
        'thumbnail_url' => $sharedThumbUrl,
        'category' => 'Test',
        'duration' => 1,
    ]);

    Sound::query()->create([
        'name' => 'B',
        'file_url' => cdnUrlForSpacesKey('sounds/b/audio/b.mp3'),
        'thumbnail_url' => $sharedThumbUrl,
        'category' => 'Test',
        'duration' => 1,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundSafeDeletion($user)));

    $this->deleteJson("/api/sounds/{$soundA->id}", [], ['Authorization' => 'Bearer tok'])->assertOk();

    Storage::disk('spaces')->assertExists($sharedThumbKey);
});

test('external file URL is ignored for Spaces cleanup and sound row is still deleted', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-external-url',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $sound = Sound::query()->create([
        'name' => 'Legacy firebase',
        'file_url' => 'https://firebasestorage.googleapis.com/v0/b/bucket/o/file.mp3?alt=media',
        'category' => 'Test',
        'duration' => 1,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundSafeDeletion($user)));

    $this->deleteJson("/api/sounds/{$sound->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertOk();

    expect(Sound::query()->find($sound->id))->toBeNull();
});

test('regular user cannot DELETE /api/sounds/{sound}', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-no-delete-sound',
        'role' => 'user',
        'admin_access_status' => 'none',
    ]);

    $sound = Sound::query()->create([
        'name' => 'Protected delete',
        'file_url' => 'https://cdn.example/p.mp3',
        'category' => 'Cat',
        'duration' => 1,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundSafeDeletion($user)));

    $this->deleteJson("/api/sounds/{$sound->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);

    expect(Sound::query()->find($sound->id))->not->toBeNull();
});
