<?php

declare(strict_types=1);

use App\Models\PresetVibe;
use App\Models\Sound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function jwtForPresetVibeUser(User $user): UnencryptedToken
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

function createSound(string $name): Sound
{
    return Sound::query()->create([
        'name' => $name,
        'file_url' => "https://cdn.example/{$name}.mp3",
        'category' => 'Test',
        'duration' => 60,
    ]);
}

test('authenticated user lists active preset vibes ordered by name', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-pv-list']);

    PresetVibe::query()->create([
        'name' => 'Zebra Demo',
        'is_active' => true,
    ]);
    PresetVibe::query()->create([
        'name' => 'Alpha Demo',
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetVibeUser($user)));

    $this->getJson('/api/preset-vibes', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Alpha Demo')
        ->assertJsonPath('data.1.name', 'Zebra Demo');
});

test('inactive preset vibes are hidden from list by default', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-pv-inactive']);
    PresetVibe::query()->create([
        'name' => 'Hidden',
        'is_active' => false,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetVibeUser($user)));

    $this->getJson('/api/preset-vibes', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('include_inactive lists inactive only for approved admin', function () {
    $admin = User::factory()->create([
        'firebase_uid' => 'fb-pv-admin-inc',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);
    $regular = User::factory()->create([
        'firebase_uid' => 'fb-pv-reg-inc',
        'role' => 'user',
        'admin_access_status' => 'none',
    ]);

    PresetVibe::query()->create(['name' => 'Active', 'is_active' => true]);
    PresetVibe::query()->create(['name' => 'Draft', 'is_active' => false]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->times(2)->andReturnUsing(function (string $tok) use ($admin, $regular) {
        return match ($tok) {
            'adm' => jwtForPresetVibeUser($admin),
            'reg' => jwtForPresetVibeUser($regular),
            default => throw new InvalidArgumentException('unexpected token'),
        };
    }));

    $this->getJson('/api/preset-vibes?include_inactive=1', ['Authorization' => 'Bearer adm'])
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/preset-vibes?include_inactive=1', ['Authorization' => 'Bearer reg'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Active');
});

test('regular user cannot POST /api/preset-vibes', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-pv-post-deny',
        'role' => 'user',
        'admin_access_status' => 'none',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetVibeUser($user)));

    $this->postJson('/api/preset-vibes', [
        'name' => 'X',
    ], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('pending user cannot POST /api/preset-vibes', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-pv-pending',
        'role' => 'user',
        'admin_access_status' => 'pending',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetVibeUser($user)));

    $this->postJson('/api/preset-vibes', [
        'name' => 'Y',
    ], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('rejected user cannot POST /api/preset-vibes', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-pv-rejected',
        'role' => 'user',
        'admin_access_status' => 'rejected',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetVibeUser($user)));

    $this->postJson('/api/preset-vibes', [
        'name' => 'Z',
    ], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('approved admin can POST PATCH PUT DELETE /api/preset-vibes', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-pv-admin-crud',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->times(4)->andReturn(jwtForPresetVibeUser($user)));

    $this->postJson('/api/preset-vibes', [
        'name' => 'New preset',
        'description' => 'Hello',
        'tags' => ['x'],
        'is_active' => true,
    ], ['Authorization' => 'Bearer tok'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'New preset');

    $id = PresetVibe::query()->where('name', 'New preset')->value('id');
    expect($id)->not->toBeNull();

    $this->patchJson("/api/preset-vibes/{$id}", [
        'name' => 'Renamed',
        'is_active' => false,
    ], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed');

    $this->putJson("/api/preset-vibes/{$id}", [
        'name' => 'Put name',
    ], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Put name');

    $this->deleteJson("/api/preset-vibes/{$id}", [], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJson(['message' => 'Preset vibe deleted.']);

    expect(PresetVibe::query()->find($id))->toBeNull();
});

test('regular user cannot PUT /api/preset-vibes/{preset}/sounds', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-pv-sync-deny',
        'role' => 'user',
        'admin_access_status' => 'none',
    ]);
    $preset = PresetVibe::query()->create(['name' => 'P', 'is_active' => true]);
    $sound = createSound('Solo');

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetVibeUser($user)));

    $this->putJson("/api/preset-vibes/{$preset->id}/sounds", [
        'sounds' => [
            ['sound_id' => $sound->id, 'volume' => 50],
        ],
    ], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('approved admin sync replaces preset vibe sounds in transaction order', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-pv-sync-ok',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);
    $preset = PresetVibe::query()->create(['name' => 'SyncMe', 'is_active' => true]);
    $a = createSound('Layer A');
    $b = createSound('Layer B');

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->times(2)->andReturn(jwtForPresetVibeUser($user)));

    $this->putJson("/api/preset-vibes/{$preset->id}/sounds", [
        'sounds' => [
            ['sound_id' => $b->id, 'sort_order' => 1, 'play_mode' => 'loop', 'volume' => 80],
            ['sound_id' => $a->id, 'sort_order' => 0, 'play_mode' => 'once', 'volume' => 70],
        ],
    ], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(2, 'data.sounds');

    expect($preset->presetVibeSounds()->count())->toBe(2);

    $this->putJson("/api/preset-vibes/{$preset->id}/sounds", [
        'sounds' => [
            ['sound_id' => $b->id, 'sort_order' => 0],
        ],
    ], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(1, 'data.sounds');

    $fresh = $preset->fresh();
    expect($fresh->presetVibeSounds()->count())->toBe(1);
    expect((int) $fresh->presetVibeSounds()->first()->sound_id)->toBe($b->id);
});

test('regular user gets 404 for inactive preset vibe show', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-pv-show-404']);
    $preset = PresetVibe::query()->create([
        'name' => 'Inactive preset',
        'is_active' => false,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetVibeUser($user)));

    $this->getJson("/api/preset-vibes/{$preset->id}", ['Authorization' => 'Bearer tok'])
        ->assertNotFound();
});

test('approved admin can GET inactive preset vibe', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-pv-show-admin',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);
    $preset = PresetVibe::query()->create([
        'name' => 'Draft preset',
        'is_active' => false,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForPresetVibeUser($user)));

    $this->getJson("/api/preset-vibes/{$preset->id}", ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Draft preset');
});
