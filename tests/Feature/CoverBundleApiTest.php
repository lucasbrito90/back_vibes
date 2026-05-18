<?php

declare(strict_types=1);

use App\Models\CoverBundle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function jwtForCoverBundleUser(User $user): UnencryptedToken
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

test('authenticated regular user can GET /api/cover-bundles active only', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-cb-list']);
    CoverBundle::query()->create([
        'name' => 'Active Pack',
        'description' => null,
        'thumbnail_url' => 'https://cdn.example/t.jpg',
        'artwork_url' => 'https://cdn.example/a.jpg',
        'player_background_url' => 'https://cdn.example/p.jpg',
        'category' => 'Demo',
        'tags' => [],
        'is_active' => true,
    ]);
    CoverBundle::query()->create([
        'name' => 'Hidden Pack',
        'description' => null,
        'thumbnail_url' => null,
        'artwork_url' => null,
        'player_background_url' => null,
        'category' => null,
        'tags' => [],
        'is_active' => false,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleUser($user)));

    $this->getJson('/api/cover-bundles', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Active Pack');
});

test('inactive cover bundles are hidden from list by default', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-cb-inactive']);
    CoverBundle::query()->create([
        'name' => 'Off',
        'is_active' => false,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleUser($user)));

    $this->getJson('/api/cover-bundles', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('regular user cannot POST /api/cover-bundles', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-cb-post-deny',
        'role' => 'user',
        'admin_access_status' => 'none',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleUser($user)));

    $this->postJson('/api/cover-bundles', [
        'name' => 'X',
        'thumbnail_url' => 'https://cdn.example/t.png',
    ], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('pending user cannot POST /api/cover-bundles', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-cb-pending',
        'role' => 'user',
        'admin_access_status' => 'pending',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleUser($user)));

    $this->postJson('/api/cover-bundles', [
        'name' => 'Y',
        'artwork_url' => 'https://cdn.example/a.png',
    ], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('rejected user cannot POST /api/cover-bundles', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-cb-rejected',
        'role' => 'user',
        'admin_access_status' => 'rejected',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleUser($user)));

    $this->postJson('/api/cover-bundles', [
        'name' => 'Z',
        'player_background_url' => 'https://cdn.example/p.png',
    ], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);
});

test('approved admin can POST /api/cover-bundles', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-cb-admin-post',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleUser($user)));

    $this->postJson('/api/cover-bundles', [
        'name' => 'New bundle',
        'description' => 'Desc',
        'thumbnail_url' => 'https://cdn.example/thumb.jpg',
        'category' => 'Cat',
        'tags' => ['a', 'b'],
        'is_active' => true,
    ], ['Authorization' => 'Bearer tok'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'New bundle')
        ->assertJsonPath('data.tags', ['a', 'b']);

    expect(CoverBundle::query()->where('name', 'New bundle')->exists())->toBeTrue();
});

test('approved admin can PATCH /api/cover-bundles/{coverBundle}', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-cb-admin-patch',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $bundle = CoverBundle::query()->create([
        'name' => 'Old',
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleUser($user)));

    $this->patchJson("/api/cover-bundles/{$bundle->id}", [
        'name' => 'Renamed',
        'is_active' => false,
    ], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.is_active', false);

    expect($bundle->fresh()->name)->toBe('Renamed');
});

test('approved admin can DELETE /api/cover-bundles/{coverBundle}', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-cb-admin-del',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);

    $bundle = CoverBundle::query()->create([
        'name' => 'Remove',
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleUser($user)));

    $this->deleteJson("/api/cover-bundles/{$bundle->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJson(['message' => 'Cover bundle deleted.']);

    expect(CoverBundle::query()->find($bundle->id))->toBeNull();
});

test('include_inactive lists inactive only for approved admin', function () {
    $admin = User::factory()->create([
        'firebase_uid' => 'fb-cb-admin-inc',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);
    $regular = User::factory()->create([
        'firebase_uid' => 'fb-cb-reg-inc',
        'role' => 'user',
        'admin_access_status' => 'none',
    ]);

    CoverBundle::query()->create(['name' => 'A', 'is_active' => true]);
    CoverBundle::query()->create(['name' => 'B', 'is_active' => false]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->times(2)->andReturnUsing(function (string $tok) use ($admin, $regular) {
        return match ($tok) {
            'adm' => jwtForCoverBundleUser($admin),
            'reg' => jwtForCoverBundleUser($regular),
            default => throw new InvalidArgumentException('unexpected token'),
        };
    }));

    $this->getJson('/api/cover-bundles?include_inactive=1', ['Authorization' => 'Bearer adm'])
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/cover-bundles?include_inactive=1', ['Authorization' => 'Bearer reg'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'A');
});

test('regular user gets 404 for inactive cover bundle show', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-cb-show-404']);
    $bundle = CoverBundle::query()->create([
        'name' => 'Inactive',
        'is_active' => false,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleUser($user)));

    $this->getJson("/api/cover-bundles/{$bundle->id}", ['Authorization' => 'Bearer tok'])
        ->assertNotFound();
});

test('approved admin can GET inactive cover bundle', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-cb-show-admin',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);
    $bundle = CoverBundle::query()->create([
        'name' => 'Draft',
        'is_active' => false,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCoverBundleUser($user)));

    $this->getJson("/api/cover-bundles/{$bundle->id}", ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Draft');
});
