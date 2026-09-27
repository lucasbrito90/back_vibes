<?php

declare(strict_types=1);

use App\Models\PresetVibe;
use App\Models\User;
use App\Models\Vibe;
use App\Models\VibeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function jwtForVibeCategoryUser(User $user): UnencryptedToken
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

function approvedAdminForVibeCategories(): User
{
    return User::factory()->create([
        'firebase_uid' => 'fb-vc-admin-'.uniqid(),
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);
}

function regularUserForVibeCategories(): User
{
    return User::factory()->create([
        'firebase_uid' => 'fb-vc-user-'.uniqid(),
        'role' => 'user',
        'admin_access_status' => 'none',
    ]);
}

test('unauthenticated requests to vibe category routes return 401', function () {
    $category = VibeCategory::query()->create([
        'slug' => 'sleep',
        'names' => ['en' => 'Sleep'],
        'sort_order' => 0,
        'is_active' => true,
    ]);

    $this->getJson('/api/vibe-categories')->assertUnauthorized();
    $this->getJson("/api/vibe-categories/{$category->id}")->assertUnauthorized();
    $this->postJson('/api/vibe-categories', [])->assertUnauthorized();
    $this->patchJson("/api/vibe-categories/{$category->id}", [])->assertUnauthorized();
    $this->deleteJson("/api/vibe-categories/{$category->id}")->assertUnauthorized();
});

test('regular user can list and show active vibe categories but not mutate', function () {
    $user = regularUserForVibeCategories();
    $category = VibeCategory::query()->create([
        'slug' => 'focus',
        'names' => ['en' => 'Focus'],
        'sort_order' => 0,
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->times(5)->with('tok')->andReturn(jwtForVibeCategoryUser($user)));

    $this->getJson('/api/vibe-categories', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'focus');

    $this->getJson("/api/vibe-categories/{$category->id}", ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'focus');

    $this->postJson('/api/vibe-categories', [
        'slug' => 'new-one',
        'names' => ['en' => 'New'],
    ], ['Authorization' => 'Bearer tok'])
        ->assertForbidden()
        ->assertJson(['message' => 'Admin access is not approved.']);

    $this->patchJson("/api/vibe-categories/{$category->id}", [
        'names' => ['en' => 'Changed'],
    ], ['Authorization' => 'Bearer tok'])
        ->assertForbidden();

    $this->deleteJson("/api/vibe-categories/{$category->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertForbidden();
});

test('approved admin can crud vibe categories', function () {
    $admin = approvedAdminForVibeCategories();

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->times(3)->with('tok')->andReturn(jwtForVibeCategoryUser($admin)));

    $this->postJson('/api/vibe-categories', [
        'slug' => 'calm',
        'names' => ['en' => 'Calm'],
        'sort_order' => 5,
    ], ['Authorization' => 'Bearer tok'])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'calm');

    $id = VibeCategory::query()->where('slug', 'calm')->value('id');
    expect($id)->not->toBeNull();

    $this->patchJson("/api/vibe-categories/{$id}", [
        'names' => ['en' => 'Calm updated'],
        'is_active' => false,
    ], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('data.names.en', 'Calm updated')
        ->assertJsonPath('data.is_active', false);

    $this->deleteJson("/api/vibe-categories/{$id}", [], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJson(['message' => 'Vibe category deleted.']);

    expect(VibeCategory::query()->find($id))->toBeNull();
});

test('store rejects duplicate slug with 422', function () {
    $admin = approvedAdminForVibeCategories();
    VibeCategory::query()->create([
        'slug' => 'taken',
        'names' => ['en' => 'Taken'],
        'sort_order' => 0,
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForVibeCategoryUser($admin)));

    $this->postJson('/api/vibe-categories', [
        'slug' => 'taken',
        'names' => ['en' => 'Other'],
    ], ['Authorization' => 'Bearer tok'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slug']);
});

test('update rejects slug change with 422', function () {
    $admin = approvedAdminForVibeCategories();
    $category = VibeCategory::query()->create([
        'slug' => 'stable',
        'names' => ['en' => 'Stable'],
        'sort_order' => 0,
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForVibeCategoryUser($admin)));

    $this->patchJson("/api/vibe-categories/{$category->id}", [
        'slug' => 'changed',
        'names' => ['en' => 'Stable'],
    ], ['Authorization' => 'Bearer tok'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slug']);
});

test('destroy returns 409 when category is linked to a preset', function () {
    $admin = approvedAdminForVibeCategories();
    $category = VibeCategory::query()->create([
        'slug' => 'linked',
        'names' => ['en' => 'Linked'],
        'sort_order' => 0,
        'is_active' => true,
    ]);
    $preset = PresetVibe::query()->create(['name' => 'P', 'is_active' => true]);
    $preset->categories()->attach($category->id);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForVibeCategoryUser($admin)));

    $this->deleteJson("/api/vibe-categories/{$category->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertStatus(409)
        ->assertJsonPath('message', 'This vibe category is currently used by one or more vibes or preset vibes and cannot be deleted.');
});

test('destroy returns 409 when category is linked to a user vibe', function () {
    $admin = approvedAdminForVibeCategories();
    $user = User::factory()->create();
    $category = VibeCategory::query()->create([
        'slug' => 'vibe-link',
        'names' => ['en' => 'Vibe link'],
        'sort_order' => 0,
        'is_active' => true,
    ]);
    $vibe = Vibe::factory()->for($user)->create();
    $vibe->categories()->attach($category->id);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForVibeCategoryUser($admin)));

    $this->deleteJson("/api/vibe-categories/{$category->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertStatus(409);
});

test('destroy succeeds when category has no pivots', function () {
    $admin = approvedAdminForVibeCategories();
    $category = VibeCategory::query()->create([
        'slug' => 'lonely',
        'names' => ['en' => 'Lonely'],
        'sort_order' => 0,
        'is_active' => true,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForVibeCategoryUser($admin)));

    $this->deleteJson("/api/vibe-categories/{$category->id}", [], ['Authorization' => 'Bearer tok'])
        ->assertOk();
});

test('include_inactive lists inactive categories only for approved admin', function () {
    $admin = approvedAdminForVibeCategories();
    $regular = regularUserForVibeCategories();

    VibeCategory::query()->create([
        'slug' => 'active-cat',
        'names' => ['en' => 'Active'],
        'sort_order' => 0,
        'is_active' => true,
    ]);
    VibeCategory::query()->create([
        'slug' => 'draft-cat',
        'names' => ['en' => 'Draft'],
        'sort_order' => 1,
        'is_active' => false,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->times(2)->andReturnUsing(function (string $tok) use ($admin, $regular) {
        return match ($tok) {
            'adm' => jwtForVibeCategoryUser($admin),
            'reg' => jwtForVibeCategoryUser($regular),
            default => throw new InvalidArgumentException('unexpected token'),
        };
    }));

    $this->getJson('/api/vibe-categories?include_inactive=1', ['Authorization' => 'Bearer adm'])
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->getJson('/api/vibe-categories?include_inactive=1', ['Authorization' => 'Bearer reg'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'active-cat');
});

test('inactive category show returns 404 for regular user', function () {
    $user = regularUserForVibeCategories();
    $category = VibeCategory::query()->create([
        'slug' => 'hidden-cat',
        'names' => ['en' => 'Hidden'],
        'sort_order' => 0,
        'is_active' => false,
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForVibeCategoryUser($user)));

    $this->getJson("/api/vibe-categories/{$category->id}", ['Authorization' => 'Bearer tok'])
        ->assertNotFound();
});

test('put preset categories sync replaces all links', function () {
    $admin = approvedAdminForVibeCategories();
    $preset = PresetVibe::query()->create(['name' => 'Kit', 'is_active' => true]);
    $c1 = VibeCategory::query()->create(['slug' => 'c1', 'names' => ['en' => 'C1'], 'sort_order' => 0, 'is_active' => true]);
    $c2 = VibeCategory::query()->create(['slug' => 'c2', 'names' => ['en' => 'C2'], 'sort_order' => 1, 'is_active' => true]);
    $c3 = VibeCategory::query()->create(['slug' => 'c3', 'names' => ['en' => 'C3'], 'sort_order' => 2, 'is_active' => true]);

    $preset->categories()->attach([$c1->id, $c2->id]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForVibeCategoryUser($admin)));

    $this->putJson("/api/preset-vibes/{$preset->id}/categories", [
        'category_ids' => [$c3->id],
    ], ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(1, 'data.categories')
        ->assertJsonPath('data.categories.0.id', $c3->id);

    expect($preset->fresh()->categories->pluck('id')->all())->toBe([$c3->id]);
});

test('put preset categories rejects unknown category id with 422', function () {
    $admin = approvedAdminForVibeCategories();
    $preset = PresetVibe::query()->create(['name' => 'Kit', 'is_active' => true]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForVibeCategoryUser($admin)));

    $this->putJson("/api/preset-vibes/{$preset->id}/categories", [
        'category_ids' => [99999],
    ], ['Authorization' => 'Bearer tok'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category_ids.0']);
});

test('regular user cannot put preset categories', function () {
    $user = regularUserForVibeCategories();
    $preset = PresetVibe::query()->create(['name' => 'Kit', 'is_active' => true]);
    $cat = VibeCategory::query()->create(['slug' => 'x', 'names' => ['en' => 'X'], 'sort_order' => 0, 'is_active' => true]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForVibeCategoryUser($user)));

    $this->putJson("/api/preset-vibes/{$preset->id}/categories", [
        'category_ids' => [$cat->id],
    ], ['Authorization' => 'Bearer tok'])
        ->assertForbidden();
});
