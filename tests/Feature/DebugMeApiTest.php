<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

test('GET /api/debug/me returns 401 without bearer token', function () {
    $this->getJson('/api/debug/me')
        ->assertStatus(401)
        ->assertJson(['message' => 'Unauthenticated.']);
});

test('GET /api/debug/me returns profile and vibe count for authenticated user', function () {
    expect(Route::has('api.debug-me'))->toBeTrue();

    $user = User::factory()->create([
        'firebase_uid' => 'fb-debug-me',
        'email' => 'debug-qa@example.com',
    ]);

    Vibe::query()->create([
        'user_id' => $user->id,
        'name' => 'Fixture Vibe',
    ]);
    Vibe::query()->create([
        'user_id' => $user->id,
        'name' => 'Second Vibe',
    ]);

    $dataset = new DataSet([
        'sub' => $user->firebase_uid,
        'email' => $user->email,
    ], 'e30.');
    $token = Mockery::mock(UnencryptedToken::class);
    $token->shouldReceive('claims')->andReturn($dataset);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn($token));

    $this->getJson('/api/debug/me', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', 'debug-qa@example.com')
        ->assertJsonPath('data.firebase_uid', 'fb-debug-me')
        ->assertJsonPath('data.vibes_count', 2);
});
