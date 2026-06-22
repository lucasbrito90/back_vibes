<?php

declare(strict_types=1);

use App\Models\PushToken;
use App\Models\User;
use App\PushNotifications\PushPlatform;
use App\PushNotifications\PushProvider;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Schema — columns and constraints
// ─────────────────────────────────────────────────────────────────────────────

test('push_tokens table has all required columns', function () {
    $columns = Schema::getColumnListing('push_tokens');

    expect($columns)
        ->toContain('id')
        ->toContain('user_id')
        ->toContain('token')
        ->toContain('platform')
        ->toContain('provider')
        ->toContain('device_id')
        ->toContain('app_version')
        ->toContain('device_model')
        ->toContain('is_active')
        ->toContain('last_seen_at')
        ->toContain('revoked_at')
        ->toContain('created_at')
        ->toContain('updated_at');
});

// ─────────────────────────────────────────────────────────────────────────────
// Model creation
// ─────────────────────────────────────────────────────────────────────────────

test('can create a push token via factory', function () {
    $pushToken = PushToken::factory()->create();

    expect($pushToken->id)->toBeInt()
        ->and($pushToken->platform)->toBe(PushPlatform::Android->value)
        ->and($pushToken->provider)->toBe(PushProvider::Fcm->value)
        ->and($pushToken->is_active)->toBeTrue();
});

test('can create a push token with explicit attributes', function () {
    $user = User::factory()->create();
    $token = 'explicit-fcm-token-'.str_repeat('a', 120);

    $pushToken = PushToken::factory()->create([
        'user_id' => $user->id,
        'token' => $token,
        'platform' => PushPlatform::Android->value,
        'provider' => PushProvider::Fcm->value,
        'device_id' => 'device-abc',
        'app_version' => '1.2.3',
        'device_model' => 'Pixel 8',
    ]);

    $fresh = $pushToken->fresh();

    expect($fresh->user_id)->toBe($user->id)
        ->and($fresh->token)->toBe($token)
        ->and($fresh->platform)->toBe('android')
        ->and($fresh->provider)->toBe('fcm')
        ->and($fresh->device_id)->toBe('device-abc')
        ->and($fresh->app_version)->toBe('1.2.3')
        ->and($fresh->device_model)->toBe('Pixel 8');
});

// ─────────────────────────────────────────────────────────────────────────────
// Token privacy
// ─────────────────────────────────────────────────────────────────────────────

test('token is hidden from toArray output', function () {
    $rawToken = 'secret-fcm-token-'.str_repeat('x', 100);
    $pushToken = PushToken::factory()->create(['token' => $rawToken]);

    $array = $pushToken->toArray();

    expect($array)->not->toHaveKey('token');

    $json = json_encode($array, JSON_THROW_ON_ERROR);

    expect($json)->not->toContain($rawToken);
});

test('tokenPreview does not expose the full token', function () {
    $rawToken = 'abcdef1234567890ghijklmnopqrstuvwxyz';
    $pushToken = PushToken::factory()->make(['token' => $rawToken]);

    $preview = $pushToken->tokenPreview();

    expect($preview)->toBe('abcdef...wxyz')
        ->and($preview)->not->toBe($rawToken)
        ->and(strlen($preview))->toBeLessThan(strlen($rawToken));
});

test('tokenPreview handles short tokens safely', function () {
    $pushToken = PushToken::factory()->make(['token' => 'short']);

    expect($pushToken->tokenPreview())->toBe('*****');
});

test('tokenHash returns sha256 hash and not the raw token', function () {
    $rawToken = 'hash-me-token-'.str_repeat('z', 80);
    $pushToken = PushToken::factory()->make(['token' => $rawToken]);

    $hash = $pushToken->tokenHash();

    expect($hash)->toBe(hash('sha256', $rawToken))
        ->and($hash)->not->toBe($rawToken)
        ->and(strlen($hash))->toBe(64);
});

// ─────────────────────────────────────────────────────────────────────────────
// Casts
// ─────────────────────────────────────────────────────────────────────────────

test('is_active is cast to boolean', function () {
    $active = PushToken::factory()->create(['is_active' => true]);
    $inactive = PushToken::factory()->inactive()->create();

    expect($active->is_active)->toBeTrue()
        ->and($inactive->fresh()->is_active)->toBeFalse();
});

test('last_seen_at is cast to datetime', function () {
    $pushToken = PushToken::factory()->create(['last_seen_at' => now()]);

    expect($pushToken->last_seen_at)->not->toBeNull()
        ->and($pushToken->last_seen_at)->toBeInstanceOf(Carbon::class);
});

test('revoked_at is cast to datetime or null', function () {
    $active = PushToken::factory()->create(['revoked_at' => null]);
    $revoked = PushToken::factory()->inactive()->create();

    expect($active->fresh()->revoked_at)->toBeNull()
        ->and($revoked->fresh()->revoked_at)->not->toBeNull()
        ->and($revoked->fresh()->revoked_at)->toBeInstanceOf(Carbon::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// Scopes
// ─────────────────────────────────────────────────────────────────────────────

test('active scope returns only active tokens', function () {
    $user = User::factory()->create();

    PushToken::factory()->count(2)->create(['user_id' => $user->id, 'is_active' => true]);
    PushToken::factory()->inactive()->create(['user_id' => $user->id]);

    $activeIds = PushToken::query()->active()->pluck('id')->all();

    expect($activeIds)->toHaveCount(2)
        ->and(PushToken::query()->active()->where('is_active', false)->exists())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// Unique constraints
// ─────────────────────────────────────────────────────────────────────────────

test('unique token constraint is enforced', function () {
    $token = 'duplicate-token-'.str_repeat('d', 100);

    PushToken::factory()->create(['token' => $token]);

    expect(fn () => PushToken::factory()->create(['token' => $token]))
        ->toThrow(QueryException::class);
});

test('same user can have multiple different tokens', function () {
    $user = User::factory()->create();

    $first = PushToken::factory()->create(['user_id' => $user->id]);
    $second = PushToken::factory()->create(['user_id' => $user->id]);

    expect($first->id)->not->toBe($second->id)
        ->and($first->token)->not->toBe($second->token)
        ->and($user->pushTokens)->toHaveCount(2);
});

test('different users cannot share the same token due to unique token index', function () {
    $token = 'shared-token-'.str_repeat('s', 100);
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    PushToken::factory()->create([
        'user_id' => $firstUser->id,
        'token' => $token,
    ]);

    expect(fn () => PushToken::factory()->create([
        'user_id' => $secondUser->id,
        'token' => $token,
    ]))->toThrow(QueryException::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// Relationships
// ─────────────────────────────────────────────────────────────────────────────

test('push token belongs to user', function () {
    $user = User::factory()->create();
    $pushToken = PushToken::factory()->create(['user_id' => $user->id]);

    expect($pushToken->user())->toBeInstanceOf(BelongsTo::class)
        ->and($pushToken->user->is($user))->toBeTrue();
});

test('user has many push tokens', function () {
    $user = User::factory()->create();
    PushToken::factory()->count(3)->create(['user_id' => $user->id]);

    expect($user->pushTokens())->toBeInstanceOf(HasMany::class)
        ->and($user->pushTokens)->toHaveCount(3);
});

// ─────────────────────────────────────────────────────────────────────────────
// Factory states
// ─────────────────────────────────────────────────────────────────────────────

test('factory inactive state deactivates token and sets revoked_at', function () {
    $pushToken = PushToken::factory()->inactive()->create();

    expect($pushToken->is_active)->toBeFalse()
        ->and($pushToken->revoked_at)->not->toBeNull();
});

test('factory android state sets android platform', function () {
    $pushToken = PushToken::factory()->android()->create();

    expect($pushToken->platform)->toBe(PushPlatform::Android->value);
});

test('factory fcm state sets fcm provider', function () {
    $pushToken = PushToken::factory()->fcm()->create();

    expect($pushToken->provider)->toBe(PushProvider::Fcm->value);
});

// ─────────────────────────────────────────────────────────────────────────────
// Enums
// ─────────────────────────────────────────────────────────────────────────────

test('PushProvider mvpAllowed only includes fcm', function () {
    $allowed = PushProvider::mvpAllowed();

    expect($allowed)->toHaveCount(1)
        ->and($allowed[0])->toBe(PushProvider::Fcm)
        ->and($allowed[0]->value)->toBe('fcm');
});

test('PushProvider values returns all provider slugs', function () {
    expect(PushProvider::values())->toBe(['fcm']);
});

test('PushPlatform mvpAllowed only includes android', function () {
    $allowed = PushPlatform::mvpAllowed();

    expect($allowed)->toHaveCount(1)
        ->and($allowed[0])->toBe(PushPlatform::Android)
        ->and($allowed[0]->value)->toBe('android');
});

test('PushPlatform values returns all platform slugs', function () {
    expect(PushPlatform::values())->toContain('android', 'ios', 'web');
});

test('PushPlatform reserved platforms are not in mvpAllowed', function () {
    $allowed = PushPlatform::mvpAllowed();

    expect($allowed)->not->toContain(PushPlatform::Ios)
        ->and($allowed)->not->toContain(PushPlatform::Web);
});
