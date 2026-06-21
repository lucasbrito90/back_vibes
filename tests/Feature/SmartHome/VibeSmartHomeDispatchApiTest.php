<?php

declare(strict_types=1);

use App\Jobs\SmartHome\SmartHomeActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\User;
use App\Models\Vibe;
use App\Models\VibeDeviceAction;
use App\SmartHome\ActionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function shDispatchJwt(User $user): UnencryptedToken
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

function shDispatchAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(shDispatchJwt($user)));
}

function shDispatchHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function shDispatchUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-dispatch-'.uniqid()]);
}

function shDispatchVibe(User $user): Vibe
{
    return Vibe::factory()->create(['user_id' => $user->id]);
}

function shDispatchDevice(User $user): Device
{
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);

    return Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
    ]);
}

function shDispatchAction(Vibe $vibe, Device $device, int $sortOrder = 0): VibeDeviceAction
{
    return VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
        'action_type' => ActionType::TurnOn->value,
        'sort_order' => $sortOrder,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Authentication
// ─────────────────────────────────────────────────────────────────────────────

it('returns 401 when unauthenticated', function () {
    $vibe = Vibe::factory()->create();

    $response = $this->postJson("/api/vibes/{$vibe->id}/smart-home/dispatch");

    $response->assertStatus(401);
});

// ─────────────────────────────────────────────────────────────────────────────
// Authorization
// ─────────────────────────────────────────────────────────────────────────────

it('returns 403 when vibe belongs to another user', function () {
    Bus::fake();

    $owner = shDispatchUser();
    $other = shDispatchUser();
    $vibe = shDispatchVibe($owner);

    shDispatchAuth($other);

    $response = $this->postJson(
        "/api/vibes/{$vibe->id}/smart-home/dispatch",
        [],
        shDispatchHeaders(),
    );

    $response->assertStatus(403);
    Bus::assertNothingDispatched();
});

// ─────────────────────────────────────────────────────────────────────────────
// Happy path — owner with actions
// ─────────────────────────────────────────────────────────────────────────────

it('owner can dispatch and receives summary', function () {
    Bus::fake();

    $user = shDispatchUser();
    $vibe = shDispatchVibe($user);
    $device = shDispatchDevice($user);
    $a1 = shDispatchAction($vibe, $device, 0);
    $a2 = shDispatchAction($vibe, $device, 1);

    shDispatchAuth($user);

    $response = $this->postJson(
        "/api/vibes/{$vibe->id}/smart-home/dispatch",
        [],
        shDispatchHeaders(),
    );

    $response->assertOk()
        ->assertJsonStructure(['data' => ['vibe_id', 'dispatched', 'skipped', 'action_ids']]);

    expect($response->json('data.vibe_id'))->toBe($vibe->id)
        ->and($response->json('data.dispatched'))->toBe(2)
        ->and($response->json('data.skipped'))->toBe(0)
        ->and($response->json('data.action_ids'))->toBe([$a1->id, $a2->id]);
});

it('dispatches one job per action', function () {
    Bus::fake();

    $user = shDispatchUser();
    $vibe = shDispatchVibe($user);
    $device = shDispatchDevice($user);
    shDispatchAction($vibe, $device, 0);
    shDispatchAction($vibe, $device, 1);

    shDispatchAuth($user);

    $this->postJson(
        "/api/vibes/{$vibe->id}/smart-home/dispatch",
        [],
        shDispatchHeaders(),
    )->assertOk();

    Bus::assertDispatchedTimes(SmartHomeActionJob::class, 2);
});

it('dispatches jobs in sort_order', function () {
    Bus::fake();

    $user = shDispatchUser();
    $vibe = shDispatchVibe($user);
    $device = shDispatchDevice($user);
    $a2 = shDispatchAction($vibe, $device, 2);
    $a0 = shDispatchAction($vibe, $device, 0);
    $a1 = shDispatchAction($vibe, $device, 1);

    shDispatchAuth($user);

    $response = $this->postJson(
        "/api/vibes/{$vibe->id}/smart-home/dispatch",
        [],
        shDispatchHeaders(),
    )->assertOk();

    expect($response->json('data.action_ids'))->toBe([$a0->id, $a1->id, $a2->id]);
});

it('response summary includes action IDs', function () {
    Bus::fake();

    $user = shDispatchUser();
    $vibe = shDispatchVibe($user);
    $device = shDispatchDevice($user);
    $action = shDispatchAction($vibe, $device, 0);

    shDispatchAuth($user);

    $response = $this->postJson(
        "/api/vibes/{$vibe->id}/smart-home/dispatch",
        [],
        shDispatchHeaders(),
    )->assertOk();

    expect($response->json('data.action_ids'))->toContain($action->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// Edge cases
// ─────────────────────────────────────────────────────────────────────────────

it('returns dispatched 0 when vibe has no device actions', function () {
    Bus::fake();

    $user = shDispatchUser();
    $vibe = shDispatchVibe($user);

    shDispatchAuth($user);

    $response = $this->postJson(
        "/api/vibes/{$vibe->id}/smart-home/dispatch",
        [],
        shDispatchHeaders(),
    )->assertOk();

    expect($response->json('data.dispatched'))->toBe(0)
        ->and($response->json('data.action_ids'))->toBe([]);

    Bus::assertNothingDispatched();
});

// ─────────────────────────────────────────────────────────────────────────────
// Safety guarantees — no HA / no HTTP / no adapter
// ─────────────────────────────────────────────────────────────────────────────

it('does not make any synchronous HTTP request to Home Assistant during dispatch', function () {
    Http::fake();
    Bus::fake();

    $user = shDispatchUser();
    $vibe = shDispatchVibe($user);
    $device = shDispatchDevice($user);
    shDispatchAction($vibe, $device);

    shDispatchAuth($user);

    $this->postJson(
        "/api/vibes/{$vibe->id}/smart-home/dispatch",
        [],
        shDispatchHeaders(),
    )->assertOk();

    // Phase 9: HA execution happens inside the queued SmartHomeActionJob, never
    // inline in the play/dispatch request. The endpoint must not hit HA itself.
    Http::assertNothingSent();
});

it('only queues the job and does not execute the provider adapter inline', function () {
    Bus::fake();
    Http::fake();

    $user = shDispatchUser();
    $vibe = shDispatchVibe($user);
    $device = shDispatchDevice($user);
    shDispatchAction($vibe, $device);

    shDispatchAuth($user);

    $this->postJson(
        "/api/vibes/{$vibe->id}/smart-home/dispatch",
        [],
        shDispatchHeaders(),
    )->assertOk();

    // Bus::fake() keeps the job on the queue (never run inline), so no adapter
    // HTTP can occur during the request — the play path stays fire-and-forget.
    Bus::assertDispatched(SmartHomeActionJob::class);
    Http::assertNothingSent();
});
