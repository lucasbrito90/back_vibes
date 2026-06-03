<?php

declare(strict_types=1);

use App\Models\Sound;
use App\Models\User;
use App\Models\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function jwtForVibeApiUser(User $user): UnencryptedToken
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

function vibeApiAuth(User $user, string $token = 'tok'): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(jwtForVibeApiUser($user)));
}

function createVibeForUser(User $user, array $overrides = []): Vibe
{
    return Vibe::query()->create([
        'user_id' => $user->id,
        'name' => 'Test Vibe',
        'description' => null,
        'is_active' => true,
        ...$overrides,
    ]);
}

test('unauthenticated user cannot access vibes', function () {
    $this->getJson('/api/vibes')->assertUnauthorized()
        ->assertJson(['message' => 'Unauthenticated.']);

    $this->postJson('/api/vibes', ['name' => 'Nope'])->assertUnauthorized();
});

test('authenticated user can list only their own vibes', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-vibe-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-vibe-bob']);

    $aliceVibe = createVibeForUser($alice, ['name' => 'Alice Rain']);
    createVibeForUser($bob, ['name' => 'Bob Storm']);

    vibeApiAuth($alice);

    $response = $this->getJson('/api/vibes', [
        'Authorization' => 'Bearer tok',
    ]);

    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$aliceVibe->id])
        ->and($response->json('data.0.name'))->toBe('Alice Rain');
});

test('user cannot see another users vibe', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-vibe-show-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-vibe-show-bob']);

    $bobVibe = createVibeForUser($bob, ['name' => 'Private']);

    vibeApiAuth($alice);

    $this->getJson("/api/vibes/{$bobVibe->id}", [
        'Authorization' => 'Bearer tok',
    ])->assertForbidden();
});

test('user can create a vibe with valid payload', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vibe-create']);

    vibeApiAuth($user);

    $response = $this->postJson('/api/vibes', [
        'name' => 'Evening Calm',
        'description' => 'Wind down',
        'is_active' => true,
    ], [
        'Authorization' => 'Bearer tok',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.name', 'Evening Calm')
        ->assertJsonPath('data.description', 'Wind down')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.sounds_count', 0);

    $vibe = Vibe::query()->findOrFail((int) $response->json('data.id'));
    expect($vibe->user_id)->toBe($user->id);
});

test('user cannot create vibe for another user via user_id in body', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-vibe-create-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-vibe-create-bob']);

    vibeApiAuth($alice);

    $response = $this->postJson('/api/vibes', [
        'name' => 'Hijack attempt',
        'user_id' => $bob->id,
    ], [
        'Authorization' => 'Bearer tok',
    ]);

    $response->assertCreated();

    $vibe = Vibe::query()->findOrFail((int) $response->json('data.id'));
    expect($vibe->user_id)->toBe($alice->id)
        ->and(Vibe::query()->where('user_id', $bob->id)->count())->toBe(0);
});

test('user can update own vibe', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vibe-update-own']);
    $vibe = createVibeForUser($user, ['name' => 'Before']);

    vibeApiAuth($user);

    $this->patchJson("/api/vibes/{$vibe->id}", [
        'name' => 'After',
        'description' => 'Updated',
        'is_active' => false,
    ], [
        'Authorization' => 'Bearer tok',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'After')
        ->assertJsonPath('data.description', 'Updated')
        ->assertJsonPath('data.is_active', false);

    expect($vibe->fresh()->name)->toBe('After');
});

test('user cannot update another users vibe', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-vibe-update-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-vibe-update-bob']);
    $bobVibe = createVibeForUser($bob, ['name' => 'Bob']);

    vibeApiAuth($alice);

    $this->patchJson("/api/vibes/{$bobVibe->id}", [
        'name' => 'Stolen',
    ], [
        'Authorization' => 'Bearer tok',
    ])->assertForbidden();

    expect($bobVibe->fresh()->name)->toBe('Bob');
});

test('user can delete own vibe', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vibe-delete-own']);
    $vibe = createVibeForUser($user);

    vibeApiAuth($user);

    $this->deleteJson("/api/vibes/{$vibe->id}", [], [
        'Authorization' => 'Bearer tok',
    ])
        ->assertOk()
        ->assertJson(['message' => 'Vibe deleted.']);

    expect(Vibe::query()->find($vibe->id))->toBeNull();
});

test('user cannot delete another users vibe', function () {
    $alice = User::factory()->create(['firebase_uid' => 'fb-vibe-delete-alice']);
    $bob = User::factory()->create(['firebase_uid' => 'fb-vibe-delete-bob']);
    $bobVibe = createVibeForUser($bob);

    vibeApiAuth($alice);

    $this->deleteJson("/api/vibes/{$bobVibe->id}", [], [
        'Authorization' => 'Bearer tok',
    ])->assertForbidden();

    expect(Vibe::query()->find($bobVibe->id))->not->toBeNull();
});

test('validation errors return 422 for invalid vibe payload', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vibe-422']);

    vibeApiAuth($user);

    $this->postJson('/api/vibes', [], [
        'Authorization' => 'Bearer tok',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);

    $this->postJson('/api/vibes', [
        'name' => str_repeat('x', 300),
    ], [
        'Authorization' => 'Bearer tok',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('VibeResource returns expected fields on show', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vibe-resource']);
    $sound = Sound::query()->create([
        'name' => 'Rain',
        'file_url' => 'https://cdn.example/rain.mp3',
        'category' => 'Nature',
        'duration' => 120,
    ]);

    $vibe = createVibeForUser($user, [
        'name' => 'Resource Vibe',
        'description' => 'Desc',
        'thumbnail_url' => 'https://cdn.example/thumb.jpg',
        'card_image_url' => 'https://cdn.example/card.jpg',
        'player_background_url' => 'https://cdn.example/bg.jpg',
        'artwork_url' => 'https://cdn.example/art.jpg',
        'is_active' => true,
    ]);
    $vibe->sounds()->attach($sound->id, ['volume' => 80, 'loop' => true, 'sort_order' => 0]);

    vibeApiAuth($user);

    $this->getJson("/api/vibes/{$vibe->id}", [
        'Authorization' => 'Bearer tok',
    ])
        ->assertOk()
        ->assertJsonPath('data.id', $vibe->id)
        ->assertJsonPath('data.name', 'Resource Vibe')
        ->assertJsonPath('data.description', 'Desc')
        ->assertJsonPath('data.thumbnail_url', 'https://cdn.example/thumb.jpg')
        ->assertJsonPath('data.card_image_url', 'https://cdn.example/card.jpg')
        ->assertJsonPath('data.player_background_url', 'https://cdn.example/bg.jpg')
        ->assertJsonPath('data.artwork_url', 'https://cdn.example/art.jpg')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.sounds_count', 1)
        ->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'description',
                'thumbnail_url',
                'card_image_url',
                'player_background_url',
                'artwork_url',
                'is_active',
                'sounds_count',
                'created_at',
                'updated_at',
            ],
        ]);
});

test('VibeResource visual fields fall back to thumbnail when dedicated fields are null', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vibe-fallback']);
    $vibe = createVibeForUser($user, [
        'thumbnail_url' => 'https://cdn.example/thumb-only.jpg',
        'card_image_url' => null,
        'player_background_url' => null,
        'artwork_url' => null,
    ]);

    vibeApiAuth($user);

    $this->getJson("/api/vibes/{$vibe->id}", [
        'Authorization' => 'Bearer tok',
    ])
        ->assertOk()
        ->assertJsonPath('data.thumbnail_url', 'https://cdn.example/thumb-only.jpg')
        ->assertJsonPath('data.card_image_url', 'https://cdn.example/thumb-only.jpg')
        ->assertJsonPath('data.player_background_url', 'https://cdn.example/thumb-only.jpg')
        ->assertJsonPath('data.artwork_url', 'https://cdn.example/thumb-only.jpg');
});

test('visual URL fields are not mass-assigned from vibe update payload', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vibe-visual-patch']);
    $vibe = createVibeForUser($user, [
        'thumbnail_url' => 'https://cdn.example/original.jpg',
    ]);

    vibeApiAuth($user);

    $this->patchJson("/api/vibes/{$vibe->id}", [
        'name' => 'Renamed',
        'thumbnail_url' => 'https://cdn.example/injected.jpg',
        'artwork_url' => 'https://cdn.example/injected-art.jpg',
    ], [
        'Authorization' => 'Bearer tok',
    ])->assertOk();

    $fresh = $vibe->fresh();
    expect($fresh->name)->toBe('Renamed')
        ->and($fresh->thumbnail_url)->toBe('https://cdn.example/original.jpg')
        ->and($fresh->artwork_url)->toBeNull();
});
