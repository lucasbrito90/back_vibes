<?php

declare(strict_types=1);

use App\Models\Sound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    \Illuminate\Support\Facades\Config::set('filesystems.disks.spaces', [
        'driver' => 's3',
        'key' => 'test',
        'secret' => 'test',
        'region' => 'tor1',
        'bucket' => 'ixora-buckets',
        'endpoint' => 'https://tor1.digitaloceanspaces.com',
        'url' => 'https://ixora-buckets.tor1.cdn.digitaloceanspaces.com',
        'use_path_style_endpoint' => false,
        'throw' => true,
    ]);

    \Illuminate\Support\Facades\Storage::fake('spaces');
});

function jwtForSoundCatalogUser(User $user): UnencryptedToken
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

test('synced regular user can GET /api/sounds', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-read-sounds']);
    Sound::query()->create([
        'name' => 'Rain',
        'file_url' => 'https://cdn.example/rain.mp3',
        'category' => 'Nature',
        'duration' => 120,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundCatalogUser($user)));

    $this->getJson('/api/sounds', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Rain');
});

test('synced regular user can GET /api/sounds/{sound}', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-read-one']);
    $sound = Sound::query()->create([
        'name' => 'Ocean',
        'file_url' => 'https://cdn.example/o.mp3',
        'category' => 'Nature',
        'duration' => 60,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundCatalogUser($user)));

    $this->getJson("/api/sounds/{$sound->id}", ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Ocean');
});

test('regular user cannot POST /api/sounds', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-regular-post',
        'role' => 'user',
        'admin_access_status' => 'none',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundCatalogUser($user)));

    $this->postJson('/api/sounds', [
        'name' => 'New sound',
        'category' => 'Test',
        'file_url' => 'https://cdn.example/new.mp3',
    ], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('pending user cannot POST /api/sounds', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-pending-post',
        'role' => 'user',
        'admin_access_status' => 'pending',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundCatalogUser($user)));

    $this->postJson('/api/sounds', [
        'name' => 'X',
        'category' => 'Y',
        'file_url' => 'https://cdn.example/x.mp3',
    ], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('rejected user cannot POST /api/sounds', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-rejected-post',
        'role' => 'user',
        'admin_access_status' => 'rejected',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundCatalogUser($user)));

    $this->postJson('/api/sounds', [
        'name' => 'X',
        'category' => 'Y',
        'file_url' => 'https://cdn.example/x.mp3',
    ], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('approved admin can POST /api/admin/sounds with audio and thumbnail uploads', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-admin-post',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundCatalogUser($user)));

    $audio = UploadedFile::fake()->create('clip.mp3', 50)->mimeType('audio/mpeg');
    $thumb = UploadedFile::fake()->create('thumb.png', 8)->mimeType('image/png');

    $response = $this->post('/api/admin/sounds', [
        'name' => 'Admin sound',
        'category' => 'Catalog',
        'duration_seconds' => 90,
        'tags' => ['ambient', 'loop'],
        'is_active' => true,
        'audio_file' => $audio,
        'thumbnail_file' => $thumb,
    ], [
        'Authorization' => 'Bearer tok',
        'Accept' => 'application/json',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Admin sound')
        ->assertJsonPath('data.duration_seconds', 90);

    $sound = Sound::query()->where('name', 'Admin sound')->first();
    expect($sound)->not->toBeNull()
        ->and($sound->file_url)->toStartWith('https://ixora-buckets.tor1.cdn.digitaloceanspaces.com/sounds/'.$sound->id.'/audio/original.mp3')
        ->and($sound->thumbnail_url)->toStartWith('https://ixora-buckets.tor1.cdn.digitaloceanspaces.com/sounds/'.$sound->id.'/thumbnail/thumbnail.png');

    Storage::disk('spaces')->assertExists('sounds/'.$sound->id.'/audio/original.mp3');
    Storage::disk('spaces')->assertExists('sounds/'.$sound->id.'/thumbnail/thumbnail.png');
});

test('approved admin cannot create sound with file_url in body', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-admin-post-url',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundCatalogUser($user)));

    $audio = UploadedFile::fake()->create('clip.mp3', 50)->mimeType('audio/mpeg');
    $thumb = UploadedFile::fake()->create('thumb.png', 8)->mimeType('image/png');

    $this->post('/api/admin/sounds', [
        'name' => 'X',
        'category' => 'Y',
        'duration_seconds' => 1,
        'tags' => ['t'],
        'audio_file' => $audio,
        'thumbnail_file' => $thumb,
        'file_url' => 'https://evil.example/a.mp3',
    ], [
        'Authorization' => 'Bearer tok',
        'Accept' => 'application/json',
    ])->assertUnprocessable();
});

test('approved admin can PATCH /api/sounds/{sound}', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-admin-patch',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $sound = Sound::query()->create([
        'name' => 'Old',
        'file_url' => 'https://cdn.example/old.mp3',
        'category' => 'Cat',
        'duration' => 10,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundCatalogUser($user)));

    $this->patchJson("/api/sounds/{$sound->id}", [
        'name' => 'Renamed',
    ], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');

    expect($sound->fresh()->name)->toBe('Renamed');
});

test('approved admin can DELETE /api/sounds/{sound}', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-admin-del',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $sound = Sound::query()->create([
        'name' => 'Remove me',
        'file_url' => 'https://cdn.example/r.mp3',
        'category' => 'Cat',
        'duration' => 5,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundCatalogUser($user)));

    $this->deleteJson("/api/sounds/{$sound->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJson(['message' => 'Sound deleted.']);

    expect(Sound::query()->find($sound->id))->toBeNull();
});

test('regular user cannot PATCH /api/sounds/{sound}', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-no-patch',
        'role' => 'user',
        'admin_access_status' => 'none',
    ]);

    $sound = Sound::query()->create([
        'name' => 'Protected',
        'file_url' => 'https://cdn.example/p.mp3',
        'category' => 'Cat',
        'duration' => 1,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundCatalogUser($user)));

    $this->patchJson("/api/sounds/{$sound->id}", ['name' => 'Hacked'], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);

    expect($sound->fresh()->name)->toBe('Protected');
});
