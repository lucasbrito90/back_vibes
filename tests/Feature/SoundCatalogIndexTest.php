<?php

declare(strict_types=1);

use App\Models\Sound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function jwtForSoundIndexUser(User $user): UnencryptedToken
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

function mockSoundIndexAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForSoundIndexUser($user)));
}

test('GET /api/sounds without bearer token is rejected', function () {
    $this->getJson('/api/sounds')
        ->assertUnauthorized();
});

test('GET /api/sounds without pagination returns full unpaginated list', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-all']);
    Sound::query()->create([
        'name' => 'Alpha',
        'file_url' => 'https://cdn.example/a.mp3',
        'category' => 'Nature',
        'duration' => 10,
    ]);
    Sound::query()->create([
        'name' => 'Beta',
        'file_url' => 'https://cdn.example/b.mp3',
        'category' => 'Urban',
        'duration' => 20,
    ]);

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissingPath('meta.current_page');
});

test('GET /api/sounds paginates when page is provided', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-page']);
    foreach (['Aaa', 'Bbb', 'Ccc'] as $i => $name) {
        Sound::query()->create([
            'name' => $name,
            'file_url' => "https://cdn.example/{$i}.mp3",
            'category' => 'Test',
            'duration' => 10,
        ]);
    }

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?page=1&per_page=2', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonPath('data.0.name', 'Aaa');
});

test('GET /api/sounds supports custom per_page within bounds', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-per-page']);
    foreach (range(1, 5) as $i) {
        Sound::query()->create([
            'name' => "Sound {$i}",
            'file_url' => "https://cdn.example/{$i}.mp3",
            'category' => 'Test',
            'duration' => 10,
        ]);
    }

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?page=1&per_page=3', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.per_page', 3)
        ->assertJsonPath('meta.last_page', 2);
});

test('GET /api/sounds filters by search term', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-search']);
    Sound::query()->create([
        'name' => 'Rain Forest',
        'file_url' => 'https://cdn.example/rain.mp3',
        'category' => 'Nature',
        'duration' => 10,
    ]);
    Sound::query()->create([
        'name' => 'City Traffic',
        'file_url' => 'https://cdn.example/city.mp3',
        'category' => 'Urban',
        'duration' => 20,
    ]);

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?search=Rain', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Rain Forest');
});

test('GET /api/sounds filters by category', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-category']);
    Sound::query()->create([
        'name' => 'Ocean',
        'file_url' => 'https://cdn.example/o.mp3',
        'category' => 'Nature',
        'duration' => 10,
    ]);
    Sound::query()->create([
        'name' => 'Subway',
        'file_url' => 'https://cdn.example/s.mp3',
        'category' => 'Urban',
        'duration' => 20,
    ]);

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?category=Urban', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Subway');
});

test('GET /api/sounds filters by tag', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-tag']);
    Sound::query()->create([
        'name' => 'Loop A',
        'file_url' => 'https://cdn.example/a.mp3',
        'category' => 'Test',
        'duration' => 10,
        'tags' => ['ambient', 'loop'],
    ]);
    Sound::query()->create([
        'name' => 'Loop B',
        'file_url' => 'https://cdn.example/b.mp3',
        'category' => 'Test',
        'duration' => 10,
        'tags' => ['nature'],
    ]);

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?tag=loop', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Loop A');
});

test('GET /api/sounds filters by status active', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-active']);
    Sound::query()->create([
        'name' => 'Active sound',
        'file_url' => 'https://cdn.example/a.mp3',
        'category' => 'Test',
        'duration' => 10,
        'is_active' => true,
    ]);
    Sound::query()->create([
        'name' => 'Inactive sound',
        'file_url' => 'https://cdn.example/i.mp3',
        'category' => 'Test',
        'duration' => 10,
        'is_active' => false,
    ]);

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?status=active', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Active sound');
});

test('GET /api/sounds status=all returns active and inactive sounds', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-status-all']);
    Sound::query()->create([
        'name' => 'Active sound',
        'file_url' => 'https://cdn.example/a.mp3',
        'category' => 'Test',
        'duration' => 10,
        'is_active' => true,
    ]);
    Sound::query()->create([
        'name' => 'Inactive sound',
        'file_url' => 'https://cdn.example/i.mp3',
        'category' => 'Test',
        'duration' => 10,
        'is_active' => false,
    ]);

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?status=all', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('approved admin can filter inactive sounds by status', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-index-admin-inactive',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);
    Sound::query()->create([
        'name' => 'Active sound',
        'file_url' => 'https://cdn.example/a.mp3',
        'category' => 'Test',
        'duration' => 10,
        'is_active' => true,
    ]);
    Sound::query()->create([
        'name' => 'Inactive sound',
        'file_url' => 'https://cdn.example/i.mp3',
        'category' => 'Test',
        'duration' => 10,
        'is_active' => false,
    ]);

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?status=inactive', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Inactive sound');
});

test('approved admin can use paginated catalog with available_categories', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-index-admin-catalog',
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);
    Sound::query()->create([
        'name' => 'One',
        'file_url' => 'https://cdn.example/1.mp3',
        'category' => 'Nature',
        'duration' => 10,
    ]);
    Sound::query()->create([
        'name' => 'Two',
        'file_url' => 'https://cdn.example/2.mp3',
        'category' => 'Urban',
        'duration' => 10,
        'is_active' => false,
    ]);

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?page=1&per_page=10&status=inactive', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('available_categories', ['Nature', 'Urban'])
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Two');
});

test('regular user cannot list inactive sounds via status filter', function () {
    $user = User::factory()->create([
        'firebase_uid' => 'fb-index-no-inactive',
        'role' => 'user',
        'admin_access_status' => 'none',
    ]);
    Sound::query()->create([
        'name' => 'Inactive sound',
        'file_url' => 'https://cdn.example/i.mp3',
        'category' => 'Test',
        'duration' => 10,
        'is_active' => false,
    ]);

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?status=inactive', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

test('GET /api/sounds sorts by column and direction', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-sort']);
    Sound::query()->create([
        'name' => 'Zulu',
        'file_url' => 'https://cdn.example/z.mp3',
        'category' => 'B',
        'duration' => 30,
    ]);
    Sound::query()->create([
        'name' => 'Alpha',
        'file_url' => 'https://cdn.example/a.mp3',
        'category' => 'A',
        'duration' => 10,
    ]);

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?sort=category&direction=desc', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('data.0.category', 'B')
        ->assertJsonPath('data.1.category', 'A');
});

test('GET /api/sounds rejects invalid sort parameter', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-invalid-sort']);
    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?sort=invalid_column', ['Authorization' => 'Bearer tok'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sort']);
});

test('GET /api/sounds rejects invalid per_page parameter', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-invalid-per-page']);
    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?per_page=0', ['Authorization' => 'Bearer tok'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['per_page']);

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?per_page=101', ['Authorization' => 'Bearer tok'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['per_page']);
});

test('GET /api/sounds rejects invalid page parameter', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-invalid-page']);
    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?page=0', ['Authorization' => 'Bearer tok'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['page']);
});

test('GET /api/sounds rejects invalid status parameter', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-invalid-status']);
    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?status=archived', ['Authorization' => 'Bearer tok'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('GET /api/sounds rejects invalid direction parameter', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-invalid-direction']);
    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?direction=sideways', ['Authorization' => 'Bearer tok'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['direction']);
});

test('paginated GET /api/sounds includes available_categories', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-index-categories']);
    Sound::query()->create([
        'name' => 'One',
        'file_url' => 'https://cdn.example/1.mp3',
        'category' => 'Nature',
        'duration' => 10,
    ]);
    Sound::query()->create([
        'name' => 'Two',
        'file_url' => 'https://cdn.example/2.mp3',
        'category' => 'Urban',
        'duration' => 10,
    ]);

    mockSoundIndexAuth($user);

    $this->getJson('/api/sounds?page=1&per_page=10', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('available_categories', ['Nature', 'Urban']);
});
