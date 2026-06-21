<?php

declare(strict_types=1);

use App\Models\CoverBundle;
use App\Models\Sound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Config::set('filesystems.disks.spaces', [
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

    Storage::fake('spaces');
});

/** @return array<string, string> */
function uploadHeaders(): array
{
    return [
        'Authorization' => 'Bearer tok',
        'Accept' => 'application/json',
    ];
}

function jwtForUploadUser(User $user): UnencryptedToken
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

function approvedAdmin(): User
{
    return User::factory()->create([
        'firebase_uid' => 'fb-upload-admin',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);
}

test('non-admin cannot upload assets', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-regular-upload',
        'role' => 'user',
        'admin_access_status' => 'none',
    ]);
    $sound = Sound::query()->create([
        'name' => 'S',
        'file_url' => 'https://cdn.example/s.mp3',
        'category' => 'x',
        'duration' => 10,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForUploadUser($user)));

    $file = UploadedFile::fake()->create('clip.mp3', 50)->mimeType('audio/mpeg');

    $this->post('/api/admin/uploads', [
        'entity_type' => 'sound',
        'entity_id' => $sound->id,
        'asset_type' => 'audio',
        'file' => $file,
    ], uploadHeaders())
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('pending admin cannot upload assets', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-pending-upload',
        'role' => 'user',
        'admin_access_status' => 'pending',
    ]);
    $sound = Sound::query()->create([
        'name' => 'S',
        'file_url' => 'https://cdn.example/s.mp3',
        'category' => 'x',
        'duration' => 10,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForUploadUser($user)));

    $file = UploadedFile::fake()->create('clip.mp3', 50)->mimeType('audio/mpeg');

    $this->post('/api/admin/uploads', [
        'entity_type' => 'sound',
        'entity_id' => $sound->id,
        'asset_type' => 'audio',
        'file' => $file,
    ], uploadHeaders())
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('rejected admin cannot upload assets', function (): void {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-rejected-upload',
        'role' => 'user',
        'admin_access_status' => 'rejected',
    ]);
    $sound = Sound::query()->create([
        'name' => 'S',
        'file_url' => 'https://cdn.example/s.mp3',
        'category' => 'x',
        'duration' => 10,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForUploadUser($user)));

    $file = UploadedFile::fake()->create('clip.mp3', 50)->mimeType('audio/mpeg');

    $this->post('/api/admin/uploads', [
        'entity_type' => 'sound',
        'entity_id' => $sound->id,
        'asset_type' => 'audio',
        'file' => $file,
    ], uploadHeaders())
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('approved admin can upload sound audio', function (): void {
    $admin = approvedAdmin();
    $sound = Sound::query()->create([
        'name' => 'Rain',
        'file_url' => 'https://cdn.example/rain.mp3',
        'category' => 'nature',
        'duration' => 120,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForUploadUser($admin)));

    $file = UploadedFile::fake()->create('clip.mp3', 50)->mimeType('audio/mpeg');

    $response = $this->post('/api/admin/uploads', [
        'entity_type' => 'sound',
        'entity_id' => $sound->id,
        'asset_type' => 'audio',
        'file' => $file,
    ], uploadHeaders());

    $response->assertCreated()
        ->assertJsonPath('data.entity_type', 'sound')
        ->assertJsonPath('data.asset_type', 'audio')
        ->assertJsonPath('data.mime_type', 'audio/mpeg')
        ->assertJsonPath('data.key', 'sounds/'.$sound->id.'/audio/original.mp3');

    expect($response->json('data.url'))
        ->toStartWith('https://ixora-buckets.tor1.cdn.digitaloceanspaces.com/sounds/'.$sound->id.'/audio/original.mp3');

    Storage::disk('spaces')->assertExists('sounds/'.$sound->id.'/audio/original.mp3');
});

test('approved admin can upload sound thumbnail image', function (): void {
    $admin = approvedAdmin();
    $sound = Sound::query()->create([
        'name' => 'Ocean',
        'file_url' => 'https://cdn.example/o.mp3',
        'category' => 'nature',
        'duration' => 60,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForUploadUser($admin)));

    $file = UploadedFile::fake()->create('thumb.png', 8)->mimeType('image/png');

    $response = $this->post('/api/admin/uploads', [
        'entity_type' => 'sound',
        'entity_id' => $sound->id,
        'asset_type' => 'thumbnail',
        'file' => $file,
    ], uploadHeaders());

    $response->assertCreated()
        ->assertJsonPath('data.key', 'sounds/'.$sound->id.'/thumbnail/thumbnail.png');

    Storage::disk('spaces')->assertExists('sounds/'.$sound->id.'/thumbnail/thumbnail.png');
});

test('approved admin can upload cover artwork', function (): void {
    $admin = approvedAdmin();
    $cover = CoverBundle::query()->create([
        'name' => 'Pack',
        'description' => null,
        'thumbnail_url' => null,
        'artwork_url' => null,
        'player_background_url' => null,
        'category' => null,
        'tags' => [],
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForUploadUser($admin)));

    $file = UploadedFile::fake()->create('art.webp', 20)->mimeType('image/webp');

    $response = $this->post('/api/admin/uploads', [
        'entity_type' => 'cover',
        'entity_id' => $cover->id,
        'asset_type' => 'artwork',
        'file' => $file,
    ], uploadHeaders());

    $response->assertCreated()
        ->assertJsonPath('data.entity_type', 'cover')
        ->assertJsonPath('data.asset_type', 'artwork')
        ->assertJsonPath('data.key', 'covers/'.$cover->id.'/artwork/artwork.webp');

    expect($response->json('data.url'))
        ->toStartWith('https://ixora-buckets.tor1.cdn.digitaloceanspaces.com/covers/'.$cover->id.'/artwork/artwork.webp');

    Storage::disk('spaces')->assertExists('covers/'.$cover->id.'/artwork/artwork.webp');
});

test('invalid entity_type fails validation', function (): void {
    $admin = approvedAdmin();
    $sound = Sound::query()->create([
        'name' => 'S',
        'file_url' => 'https://cdn.example/s.mp3',
        'category' => 'x',
        'duration' => 1,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForUploadUser($admin)));

    $file = UploadedFile::fake()->create('clip.mp3', 10)->mimeType('audio/mpeg');

    $this->post('/api/admin/uploads', [
        'entity_type' => 'planet',
        'entity_id' => $sound->id,
        'asset_type' => 'audio',
        'file' => $file,
    ], uploadHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['entity_type']);
});

test('invalid asset_type for entity fails validation', function (): void {
    $admin = approvedAdmin();
    $sound = Sound::query()->create([
        'name' => 'S',
        'file_url' => 'https://cdn.example/s.mp3',
        'category' => 'x',
        'duration' => 1,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForUploadUser($admin)));

    $file = UploadedFile::fake()->create('clip.mp3', 10)->mimeType('audio/mpeg');

    $this->post('/api/admin/uploads', [
        'entity_type' => 'sound',
        'entity_id' => $sound->id,
        'asset_type' => 'player_background',
        'file' => $file,
    ], uploadHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['asset_type']);
});

test('invalid MIME for sound audio fails validation', function (): void {
    $admin = approvedAdmin();
    $sound = Sound::query()->create([
        'name' => 'S',
        'file_url' => 'https://cdn.example/s.mp3',
        'category' => 'x',
        'duration' => 1,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForUploadUser($admin)));

    $file = UploadedFile::fake()->create('photo.jpg', 8)->mimeType('image/jpeg');

    $this->post('/api/admin/uploads', [
        'entity_type' => 'sound',
        'entity_id' => $sound->id,
        'asset_type' => 'audio',
        'file' => $file,
    ], uploadHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

test('file exceeding max size fails validation', function (): void {
    $admin = approvedAdmin();
    $sound = Sound::query()->create([
        'name' => 'S',
        'file_url' => 'https://cdn.example/s.mp3',
        'category' => 'x',
        'duration' => 1,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForUploadUser($admin)));

    $file = UploadedFile::fake()->create('big.mp3', 25601)->mimeType('audio/mpeg');

    $this->post('/api/admin/uploads', [
        'entity_type' => 'sound',
        'entity_id' => $sound->id,
        'asset_type' => 'audio',
        'file' => $file,
    ], uploadHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

test('missing entity id fails validation', function (): void {
    $admin = approvedAdmin();

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForUploadUser($admin)));

    $file = UploadedFile::fake()->create('clip.mp3', 10)->mimeType('audio/mpeg');

    $this->post('/api/admin/uploads', [
        'entity_type' => 'sound',
        'entity_id' => 999_999,
        'asset_type' => 'audio',
        'file' => $file,
    ], uploadHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['entity_id']);
});

test('response exposes public CDN URL', function (): void {
    $admin = approvedAdmin();
    $subject = User::factory()->create([
        'firebase_uid' => 'fb-avatar-target',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForUploadUser($admin)));

    $file = UploadedFile::fake()->create('avatar.jpg', 8)->mimeType('image/jpeg');

    $response = $this->post('/api/admin/uploads', [
        'entity_type' => 'user',
        'entity_id' => $subject->id,
        'asset_type' => 'avatar',
        'file' => $file,
    ], uploadHeaders());

    $response->assertCreated();

    $url = (string) $response->json('data.url');
    expect($url)->toStartWith('https://ixora-buckets.tor1.cdn.digitaloceanspaces.com/')
        ->and($url)->toContain('users/'.$subject->id.'/avatar/avatar.jpg')
        ->and($response->json('data.size'))->toBeInt();
});
