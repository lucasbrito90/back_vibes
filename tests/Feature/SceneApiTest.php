<?php

declare(strict_types=1);

use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function sceneApiJwt(User $user): UnencryptedToken
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

function sceneApiAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(sceneApiJwt($user)));
}

function sceneApiHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function createSceneForUser(User $user, array $overrides = []): Scene
{
    return Scene::factory()->create([
        'user_id' => $user->id,
        ...$overrides,
    ]);
}

test('unauthenticated user cannot access scenes', function () {
    $this->getJson('/api/scenes')->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);

    $this->postJson('/api/scenes', ['name' => 'Nope'])->assertUnauthorized();
});

test('authenticated user can list only their own scenes', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-scene-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-scene-bob']);

    $aliceScene = createSceneForUser($alice, ['name' => 'Alice Evening']);
    createSceneForUser($bob, ['name' => 'Bob Morning']);

    sceneApiAuth($alice);

    $response = $this->getJson('/api/scenes', sceneApiHeaders());

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$aliceScene->id])
        ->and($response->json('data.0.name'))->toBe('Alice Evening');
});

test('user cannot show another users scene', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-scene-show-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-scene-show-bob']);

    $bobScene = createSceneForUser($bob, ['name' => 'Private Scene']);

    sceneApiAuth($alice);

    $this->getJson("/api/scenes/{$bobScene->id}", sceneApiHeaders())
        ->assertNotFound();
});

test('user can create a scene with valid payload', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-scene-create']);

    sceneApiAuth($user);

    $response = $this->postJson('/api/scenes', [
        'name' => 'Movie Night',
        'description' => 'Dim lights',
    ], sceneApiHeaders());

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Movie Night')
        ->assertJsonPath('data.description', 'Dim lights');

    $scene = Scene::query()->findOrFail((int) $response->json('data.id'));
    expect($scene->user_id)->toBe($user->id);
});

test('user cannot create scene for another user via user_id in body', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-scene-create-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-scene-create-bob']);

    sceneApiAuth($alice);

    $response = $this->postJson('/api/scenes', [
        'name' => 'Hijack attempt',
        'user_id' => $bob->id,
    ], sceneApiHeaders());

    $response->assertCreated();

    $scene = Scene::query()->findOrFail((int) $response->json('data.id'));
    expect($scene->user_id)->toBe($alice->id)
        ->and(Scene::query()->where('user_id', $bob->id)->count())->toBe(0);
});

test('user can show own scene', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-scene-show-own']);
    $scene = createSceneForUser($user, [
        'name' => 'Bedtime',
        'description' => 'Lights off',
    ]);

    sceneApiAuth($user);

    $this->getJson("/api/scenes/{$scene->id}", sceneApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.id', $scene->id)
        ->assertJsonPath('data.name', 'Bedtime')
        ->assertJsonPath('data.description', 'Lights off')
        ->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'description',
                'created_at',
                'updated_at',
            ],
        ]);
});

test('user can update own scene', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-scene-update-own']);
    $scene = createSceneForUser($user, ['name' => 'Before']);

    sceneApiAuth($user);

    $this->patchJson("/api/scenes/{$scene->id}", [
        'name' => 'After',
        'description' => 'Updated',
    ], sceneApiHeaders())
        ->assertOk()
        ->assertJsonPath('data.name', 'After')
        ->assertJsonPath('data.description', 'Updated');

    expect($scene->fresh()->name)->toBe('After');
});

test('user cannot update another users scene', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-scene-update-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-scene-update-bob']);
    $bobScene = createSceneForUser($bob, ['name' => 'Bob Scene']);

    sceneApiAuth($alice);

    $this->patchJson("/api/scenes/{$bobScene->id}", [
        'name' => 'Stolen',
    ], sceneApiHeaders())
        ->assertNotFound();

    expect($bobScene->fresh()->name)->toBe('Bob Scene');
});

test('user can delete own scene', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-scene-delete-own']);
    $scene = createSceneForUser($user);

    sceneApiAuth($user);

    $this->deleteJson("/api/scenes/{$scene->id}", [], sceneApiHeaders())
        ->assertOk()
        ->assertJson(['message' => 'Scene deleted.']);

    expect(Scene::query()->find($scene->id))->toBeNull();
});

test('user cannot delete another users scene', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-scene-delete-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-scene-delete-bob']);
    $bobScene = createSceneForUser($bob);

    sceneApiAuth($alice);

    $this->deleteJson("/api/scenes/{$bobScene->id}", [], sceneApiHeaders())
        ->assertNotFound();

    expect(Scene::query()->find($bobScene->id))->not->toBeNull();
});

test('deleting a scene with actions cascades without blocking', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-scene-delete-cascade']);
    $scene = createSceneForUser($user);
    $action = SceneAction::factory()->create(['scene_id' => $scene->id]);

    sceneApiAuth($user);

    $this->deleteJson("/api/scenes/{$scene->id}", [], sceneApiHeaders())
        ->assertOk();

    expect(Scene::query()->find($scene->id))->toBeNull()
        ->and(SceneAction::query()->find($action->id))->toBeNull();
});

test('validation errors return 422 for invalid scene payload', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-scene-422']);

    sceneApiAuth($user);

    $this->postJson('/api/scenes', [], sceneApiHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->postJson('/api/scenes', [
        'name' => '',
    ], sceneApiHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $scene = createSceneForUser($user);

    $this->patchJson("/api/scenes/{$scene->id}", [
        'name' => '',
    ], sceneApiHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});
