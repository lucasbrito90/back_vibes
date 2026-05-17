<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

test('POST /api/auth/sync returns 401 without bearer token', function () {
    $this->postJson('/api/auth/sync')
        ->assertStatus(401)
        ->assertJson(['message' => 'Missing Firebase ID token.']);
});

test('POST /api/auth/sync returns 401 when token verification fails', function () {
    $this->mock(Auth::class, function ($mock) {
        $mock->shouldReceive('verifyIdToken')
            ->once()
            ->with('bad-token')
            ->andThrow(new FailedToVerifyToken('Invalid token.'));
    });

    $this->postJson('/api/auth/sync', [], [
        'Authorization' => 'Bearer bad-token',
    ])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid Firebase ID token.']);
});

test('POST /api/auth/sync creates local user from firebase claims', function () {
    $dataset = new DataSet([
        'sub' => 'firebase-uid-xyz',
        'email' => 'sync@test.com',
        'name' => 'Synced User',
        'picture' => 'https://example.com/avatar.jpg',
    ], 'e30.');
    $token = Mockery::mock(UnencryptedToken::class);
    $token->shouldReceive('claims')->once()->andReturn($dataset);

    $this->mock(Auth::class, function ($mock) use ($token) {
        $mock->shouldReceive('verifyIdToken')
            ->once()
            ->with('valid-token')
            ->andReturn($token);
    });

    $this->postJson('/api/auth/sync', [], [
        'Authorization' => 'Bearer valid-token',
    ])
        ->assertOk()
        ->assertJsonPath('data.firebase_uid', 'firebase-uid-xyz')
        ->assertJsonPath('data.email', 'sync@test.com')
        ->assertJsonPath('data.name', 'Synced User')
        ->assertJsonPath('data.role', 'user')
        ->assertJsonPath('data.admin_access_status', 'none')
        ->assertJsonPath('data.avatar_url', 'https://example.com/avatar.jpg');

    expect(User::query()->where('firebase_uid', 'firebase-uid-xyz')->exists())->toBeTrue();
});

test('POST /api/auth/sync updates profile when user exists', function () {
    User::factory()->create([
        'firebase_uid' => 'same-uid',
        'email' => 'old@test.com',
        'name' => 'Old Name',
        'avatar_url' => null,
    ]);

    $dataset = new DataSet([
        'sub' => 'same-uid',
        'email' => 'new@test.com',
        'name' => 'New Name',
        'picture' => 'https://example.com/new.jpg',
    ], 'e30.');
    $token = Mockery::mock(UnencryptedToken::class);
    $token->shouldReceive('claims')->once()->andReturn($dataset);

    $this->mock(Auth::class, function ($mock) use ($token) {
        $mock->shouldReceive('verifyIdToken')
            ->once()
            ->with('valid-token')
            ->andReturn($token);
    });

    $this->postJson('/api/auth/sync', [], [
        'Authorization' => 'Bearer valid-token',
    ])
        ->assertOk()
        ->assertJsonPath('data.email', 'new@test.com')
        ->assertJsonPath('data.name', 'New Name')
        ->assertJsonPath('data.avatar_url', 'https://example.com/new.jpg');

    expect(User::query()->where('firebase_uid', 'same-uid')->first()?->email)->toBe('new@test.com');
});
