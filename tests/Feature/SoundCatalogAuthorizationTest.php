<?php

declare(strict_types=1);

use App\Models\Sound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

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

test('approved admin can POST /api/sounds', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-admin-post',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundCatalogUser($user)));

    $this->postJson('/api/sounds', [
        'name' => 'Admin sound',
        'category' => 'Catalog',
        'file_url' => 'https://cdn.example/admin.mp3',
        'duration_seconds' => 90,
        'tags' => ['ambient', 'loop'],
        'is_active' => true,
    ], ['Authorization' => 'Bearer tok'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Admin sound')
        ->assertJsonPath('data.duration_seconds', 90);

    expect(Sound::query()->where('name', 'Admin sound')->exists())->toBeTrue();
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
