<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Temporarily replace a named rate limiter with a limit of $max per minute,
 * keyed by a unique string so each test gets its own isolated bucket.
 * Returns the unique key (not needed by callers, but useful for debugging).
 */
function withTestRateLimit(string $limiterName, int $max): string
{
    $key = 'test-'.$limiterName.'-'.uniqid();
    RateLimiter::for($limiterName, fn () => Limit::perMinute($max)->by($key));

    return $key;
}

function jwtForRateLimitUser(User $user): UnencryptedToken
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

function rateLimitApiAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(jwtForRateLimitUser($user)));
}

// ── Auth limiter — POST /auth/firebase ────────────────────────────────────────

test('auth rate limiter: request within limit on /auth/firebase returns non-429', function () {
    withTestRateLimit('auth', 2);

    // Any response other than 429 means the throttle middleware passed the request through.
    // The controller returns 401 (missing token) because we send no bearer token — that is fine.
    $this->postJson('/api/auth/firebase')->assertStatus(401);
});

test('auth rate limiter: exceeding limit on /auth/firebase returns 429', function () {
    withTestRateLimit('auth', 2);

    $this->postJson('/api/auth/firebase'); // 1st — passes
    $this->postJson('/api/auth/firebase'); // 2nd — passes (at the limit)
    $this->postJson('/api/auth/firebase')->assertStatus(429); // 3rd — throttled
});

// ── Auth limiter — POST /auth/sync ────────────────────────────────────────────

test('auth rate limiter: request within limit on /auth/sync returns non-429', function () {
    withTestRateLimit('auth', 2);

    // /auth/sync without a valid bearer token returns 401; that is the controller's
    // response, not the throttle middleware — so it is not 429.
    $this->postJson('/api/auth/sync')->assertStatus(401);
});

test('auth rate limiter: exceeding limit on /auth/sync returns 429', function () {
    withTestRateLimit('auth', 2);

    $this->postJson('/api/auth/sync'); // 1st — passes
    $this->postJson('/api/auth/sync'); // 2nd — passes (at the limit)
    $this->postJson('/api/auth/sync')->assertStatus(429); // 3rd — throttled
});

// ── API limiter — authenticated routes ───────────────────────────────────────

test('api rate limiter: request within limit on authenticated route returns non-429', function () {
    withTestRateLimit('api', 2);

    $user = User::factory()->create(['firebase_uid' => 'fb-rl-within']);
    rateLimitApiAuth($user);

    $this->getJson('/api/vibes', ['Authorization' => 'Bearer tok'])->assertStatus(200);
});

test('api rate limiter: exceeding limit on authenticated route returns 429', function () {
    withTestRateLimit('api', 2);

    $user = User::factory()->create(['firebase_uid' => 'fb-rl-exceed']);
    rateLimitApiAuth($user);

    $this->getJson('/api/vibes', ['Authorization' => 'Bearer tok']); // 1st — passes
    $this->getJson('/api/vibes', ['Authorization' => 'Bearer tok']); // 2nd — passes (at the limit)
    $this->getJson('/api/vibes', ['Authorization' => 'Bearer tok'])->assertStatus(429); // 3rd — throttled
});
