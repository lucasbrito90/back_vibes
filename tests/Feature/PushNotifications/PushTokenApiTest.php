<?php

declare(strict_types=1);

use App\Models\PushToken;
use App\Models\User;
use App\PushNotifications\PushPlatform;
use App\PushNotifications\PushProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function ptJwt(User $user): UnencryptedToken
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

function ptAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(ptJwt($user)));
}

function ptHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function ptUser(): User
{
    return User::factory()->create(['firebase_uid' => 'fb-pt-'.uniqid()]);
}

function ptPayload(array $overrides = []): array
{
    return array_merge([
        'token' => 'fcm-test-token-'.str_repeat('a', 140).'-'.uniqid(),
        'platform' => PushPlatform::Android->value,
        'provider' => PushProvider::Fcm->value,
        'device_id' => 'device-abc',
        'app_version' => '0.0.1',
        'device_model' => 'Pixel 7',
    ], $overrides);
}

// ─────────────────────────────────────────────────────────────────────────────
// Authentication guard — all endpoints require firebase.auth
// ─────────────────────────────────────────────────────────────────────────────

it('unauthenticated store returns 401', function () {
    $this->postJson('/api/push-tokens', ptPayload())
        ->assertStatus(401);
});

it('unauthenticated refresh returns 401', function () {
    $this->postJson('/api/push-tokens/refresh', ptPayload())
        ->assertStatus(401);
});

it('unauthenticated delete returns 401', function () {
    $pushToken = PushToken::factory()->create();

    $this->deleteJson("/api/push-tokens/{$pushToken->id}")
        ->assertStatus(401);
});

// ─────────────────────────────────────────────────────────────────────────────
// store — happy path
// ─────────────────────────────────────────────────────────────────────────────

it('store creates token for authenticated user', function () {
    $user = ptUser();
    ptAuth($user);

    $payload = ptPayload();

    $response = $this->postJson('/api/push-tokens', $payload, ptHeaders());

    $response->assertStatus(201);

    $this->assertDatabaseHas('push_tokens', [
        'user_id' => $user->id,
        'platform' => PushPlatform::Android->value,
        'provider' => PushProvider::Fcm->value,
        'is_active' => true,
    ]);
});

it('store returns correct resource structure', function () {
    $user = ptUser();
    ptAuth($user);

    $response = $this->postJson('/api/push-tokens', ptPayload(), ptHeaders());

    $response->assertStatus(201)
        ->assertJsonStructure([
            'data' => [
                'id',
                'platform',
                'provider',
                'device_id',
                'app_version',
                'device_model',
                'is_active',
                'last_seen_at',
                'revoked_at',
                'created_at',
                'updated_at',
                'token_preview',
            ],
        ]);
});

it('store forces user_id from auth and ignores injected user_id', function () {
    $user = ptUser();
    $attacker = ptUser();
    ptAuth($user);

    $payload = ptPayload(['user_id' => $attacker->id]);

    $response = $this->postJson('/api/push-tokens', $payload, ptHeaders());

    // user_id is prohibited — must be rejected with 422
    $response->assertStatus(422)
        ->assertJsonValidationErrors(['user_id']);
});

// ─────────────────────────────────────────────────────────────────────────────
// store — platform / provider validation
// ─────────────────────────────────────────────────────────────────────────────

it('store rejects ios platform in MVP', function () {
    $user = ptUser();
    ptAuth($user);

    $this->postJson('/api/push-tokens', ptPayload(['platform' => 'ios']), ptHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['platform']);
});

it('store rejects web platform in MVP', function () {
    $user = ptUser();
    ptAuth($user);

    $this->postJson('/api/push-tokens', ptPayload(['platform' => 'web']), ptHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['platform']);
});

it('store rejects unknown platform', function () {
    $user = ptUser();
    ptAuth($user);

    $this->postJson('/api/push-tokens', ptPayload(['platform' => 'huawei']), ptHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['platform']);
});

it('store rejects unsupported provider', function () {
    $user = ptUser();
    ptAuth($user);

    $this->postJson('/api/push-tokens', ptPayload(['provider' => 'apns']), ptHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['provider']);
});

// ─────────────────────────────────────────────────────────────────────────────
// store — prohibited field injection
// ─────────────────────────────────────────────────────────────────────────────

it('store rejects is_active injection', function () {
    $user = ptUser();
    ptAuth($user);

    $this->postJson('/api/push-tokens', ptPayload(['is_active' => false]), ptHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['is_active']);
});

it('store rejects revoked_at injection', function () {
    $user = ptUser();
    ptAuth($user);

    $this->postJson('/api/push-tokens', ptPayload(['revoked_at' => now()->toISOString()]), ptHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['revoked_at']);
});

it('store rejects last_seen_at injection', function () {
    $user = ptUser();
    ptAuth($user);

    $this->postJson('/api/push-tokens', ptPayload(['last_seen_at' => now()->toISOString()]), ptHeaders())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['last_seen_at']);
});

// ─────────────────────────────────────────────────────────────────────────────
// store — upsert / deduplification
// ─────────────────────────────────────────────────────────────────────────────

it('store re-register same token does not duplicate', function () {
    $user = ptUser();
    ptAuth($user);

    $payload = ptPayload();

    $this->postJson('/api/push-tokens', $payload, ptHeaders())->assertStatus(201);

    // Re-mock after first request consumed the mock
    ptAuth($user);
    $this->postJson('/api/push-tokens', $payload, ptHeaders())->assertStatus(200);

    $this->assertDatabaseCount('push_tokens', 1);
});

it('store re-register same token reactivates inactive token', function () {
    $user = ptUser();

    $rawToken = 'fcm-reactivate-'.str_repeat('b', 140);

    PushToken::factory()->inactive()->create([
        'user_id' => $user->id,
        'token' => $rawToken,
    ]);

    ptAuth($user);

    $this->postJson('/api/push-tokens', ptPayload(['token' => $rawToken]), ptHeaders())
        ->assertStatus(200);

    $this->assertDatabaseHas('push_tokens', [
        'token' => $rawToken,
        'is_active' => true,
        'revoked_at' => null,
    ]);
});

/**
 * MVP ownership reassignment: re-registering a token under a different authenticated
 * user reassigns ownership to the current user. This is the safe MVP behavior (ADR-018):
 * unique token → one owner, and the current authenticated user always wins.
 */
it('store same token under another authenticated user reassigns owner', function () {
    $userA = ptUser();
    $userB = ptUser();

    $rawToken = 'fcm-reassign-'.str_repeat('c', 140);

    PushToken::factory()->create(['user_id' => $userA->id, 'token' => $rawToken]);

    ptAuth($userB);

    $this->postJson('/api/push-tokens', ptPayload(['token' => $rawToken]), ptHeaders())
        ->assertStatus(200);

    // Token is now owned by userB; only one row exists
    $this->assertDatabaseCount('push_tokens', 1);
    $this->assertDatabaseHas('push_tokens', ['token' => $rawToken, 'user_id' => $userB->id]);
});

// ─────────────────────────────────────────────────────────────────────────────
// store — provider default
// ─────────────────────────────────────────────────────────────────────────────

it('provider defaults to fcm when omitted from store', function () {
    $user = ptUser();
    ptAuth($user);

    $payload = ptPayload();
    unset($payload['provider']);

    $response = $this->postJson('/api/push-tokens', $payload, ptHeaders());

    $response->assertStatus(201);
    expect($response->json('data.provider'))->toBe(PushProvider::Fcm->value);
});

// ─────────────────────────────────────────────────────────────────────────────
// refresh — happy path
// ─────────────────────────────────────────────────────────────────────────────

it('refresh with old_token deactivates old token owned by user', function () {
    $user = ptUser();

    $oldToken = 'fcm-old-'.str_repeat('d', 140);
    $newToken = 'fcm-new-'.str_repeat('e', 140);

    PushToken::factory()->create(['user_id' => $user->id, 'token' => $oldToken]);

    ptAuth($user);

    $this->postJson('/api/push-tokens/refresh', [
        'old_token' => $oldToken,
        'token' => $newToken,
        'platform' => PushPlatform::Android->value,
    ], ptHeaders())->assertOk();

    $this->assertDatabaseHas('push_tokens', ['token' => $oldToken, 'is_active' => false]);
    $this->assertDatabaseHas('push_tokens', ['token' => $newToken, 'is_active' => true]);
});

it('refresh does not deactivate old_token owned by another user', function () {
    $owner = ptUser();
    $other = ptUser();

    $oldToken = 'fcm-other-old-'.str_repeat('f', 140);
    $newToken = 'fcm-other-new-'.str_repeat('g', 140);

    // Token belongs to $owner, not $other
    PushToken::factory()->create(['user_id' => $owner->id, 'token' => $oldToken]);

    ptAuth($other);

    $this->postJson('/api/push-tokens/refresh', [
        'old_token' => $oldToken,
        'token' => $newToken,
        'platform' => PushPlatform::Android->value,
    ], ptHeaders())->assertOk();

    // Other user's old token must remain active and untouched
    $this->assertDatabaseHas('push_tokens', ['token' => $oldToken, 'is_active' => true, 'user_id' => $owner->id]);
    // New token belongs to $other
    $this->assertDatabaseHas('push_tokens', ['token' => $newToken, 'is_active' => true, 'user_id' => $other->id]);
});

it('refresh upserts new token for the authenticated user', function () {
    $user = ptUser();
    ptAuth($user);

    $newToken = 'fcm-fresh-'.str_repeat('h', 140);

    $response = $this->postJson('/api/push-tokens/refresh', [
        'token' => $newToken,
        'platform' => PushPlatform::Android->value,
    ], ptHeaders());

    $response->assertOk();

    $this->assertDatabaseHas('push_tokens', [
        'user_id' => $user->id,
        'token' => $newToken,
        'is_active' => true,
    ]);
});

it('refresh rejects prohibited fields', function () {
    $user = ptUser();
    ptAuth($user);

    $this->postJson('/api/push-tokens/refresh', [
        'token' => 'fcm-test-'.str_repeat('i', 140),
        'platform' => PushPlatform::Android->value,
        'user_id' => 99,
    ], ptHeaders())->assertStatus(422)->assertJsonValidationErrors(['user_id']);
});

// ─────────────────────────────────────────────────────────────────────────────
// destroy — happy path
// ─────────────────────────────────────────────────────────────────────────────

it('delete deactivates own token and returns 204', function () {
    $user = ptUser();
    $pushToken = PushToken::factory()->create(['user_id' => $user->id]);

    ptAuth($user);

    $this->deleteJson("/api/push-tokens/{$pushToken->id}", [], ptHeaders())
        ->assertStatus(204);

    $this->assertDatabaseHas('push_tokens', [
        'id' => $pushToken->id,
        'is_active' => false,
    ]);

    expect($pushToken->fresh()->revoked_at)->not->toBeNull();
});

it('delete foreign token returns 403', function () {
    $owner = ptUser();
    $attacker = ptUser();

    $pushToken = PushToken::factory()->create(['user_id' => $owner->id]);

    ptAuth($attacker);

    $this->deleteJson("/api/push-tokens/{$pushToken->id}", [], ptHeaders())
        ->assertStatus(403);

    // Token remains active
    $this->assertDatabaseHas('push_tokens', ['id' => $pushToken->id, 'is_active' => true]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Token privacy — ADR-021
// ─────────────────────────────────────────────────────────────────────────────

it('API response never contains raw token on store', function () {
    $user = ptUser();
    ptAuth($user);

    $rawToken = 'fcm-private-'.str_repeat('j', 140);

    $response = $this->postJson('/api/push-tokens', ptPayload(['token' => $rawToken]), ptHeaders());

    $response->assertStatus(201);

    $body = $response->getContent();

    expect($body)->not->toContain($rawToken)
        ->and($response->json('data'))->not->toHaveKey('token');
});

it('API response never contains raw token on refresh', function () {
    $user = ptUser();
    ptAuth($user);

    $rawToken = 'fcm-refresh-private-'.str_repeat('k', 140);

    $response = $this->postJson('/api/push-tokens/refresh', ptPayload(['token' => $rawToken]), ptHeaders());

    $response->assertOk();

    $body = $response->getContent();

    expect($body)->not->toContain($rawToken)
        ->and($response->json('data'))->not->toHaveKey('token');
});

it('API response includes token_preview on store', function () {
    $user = ptUser();
    ptAuth($user);

    $response = $this->postJson('/api/push-tokens', ptPayload(), ptHeaders());

    $response->assertStatus(201);

    expect($response->json('data.token_preview'))->not->toBeNull()
        ->and($response->json('data.token_preview'))->toBeString();
});

it('token_preview does not expose full token', function () {
    $user = ptUser();
    ptAuth($user);

    $rawToken = 'fcm-preview-'.str_repeat('l', 140);

    $response = $this->postJson('/api/push-tokens', ptPayload(['token' => $rawToken]), ptHeaders());

    $preview = $response->json('data.token_preview');

    expect($preview)->not->toBe($rawToken)
        ->and(strlen($preview))->toBeLessThan(strlen($rawToken));
});

it('full token is never logged during store', function () {
    $loggedMessages = [];

    Log::listen(function (string $level, string $message, array $context) use (&$loggedMessages): void {
        $loggedMessages[] = $message.' '.json_encode($context, JSON_THROW_ON_ERROR);
    });

    $user = ptUser();
    ptAuth($user);

    $rawToken = 'fcm-log-check-'.str_repeat('m', 140);

    $this->postJson('/api/push-tokens', ptPayload(['token' => $rawToken]), ptHeaders())
        ->assertStatus(201);

    foreach ($loggedMessages as $entry) {
        expect($entry)->not->toContain($rawToken);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Unique constraint — handled via upsert, never a 500
// ─────────────────────────────────────────────────────────────────────────────

it('duplicate token unique constraint is handled via upsert and does not produce 500', function () {
    $user = ptUser();

    $rawToken = 'fcm-upsert-'.str_repeat('n', 140);

    ptAuth($user);
    $first = $this->postJson('/api/push-tokens', ptPayload(['token' => $rawToken]), ptHeaders());
    $first->assertStatus(201);

    ptAuth($user);
    $second = $this->postJson('/api/push-tokens', ptPayload(['token' => $rawToken]), ptHeaders());
    $second->assertStatus(200);

    expect($first->json('data.id'))->toBe($second->json('data.id'));
});
