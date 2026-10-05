<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\ProviderConnectionAttempt;
use App\Models\User;
use App\SmartHome\ConnectionStatus;
use App\SmartHome\ProviderType;
use App\SmartHome\Services\ConnectionTestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

const TEST_HA_BASE = 'https://ha.test-prv02.test';

function pctJwt(User $user): UnencryptedToken
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

function pctAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(pctJwt($user)));
}

function pctHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function pctUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-pct-'.uniqid()]);
}

function pctHaConnection(User $user): ProviderConnection
{
    $conn = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'config' => ['base_url' => TEST_HA_BASE],
    ]);
    $conn->setEncryptedCredentials(['access_token' => 'test-token']);
    $conn->save();

    return $conn;
}

function pctGhConnection(User $user): ProviderConnection
{
    return ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'name' => 'My Google Home '.uniqid(),
        'provider' => ProviderType::GoogleHome->value,
        'config' => [],
        'encrypted_credentials' => null,
        'status' => ConnectionStatus::Unknown->value,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Schema (PRV-02 additions)
// ─────────────────────────────────────────────────────────────────────────────

test('provider_connections table has last_synced_at column', function () {
    expect(Schema::getColumnListing('provider_connections'))->toContain('last_synced_at');
});

test('provider_connection_attempts table exists with required columns', function () {
    $columns = Schema::getColumnListing('provider_connection_attempts');

    expect($columns)
        ->toContain('id')
        ->toContain('provider_connection_id')
        ->toContain('source')
        ->toContain('outcome')
        ->toContain('failure_reason')
        ->toContain('latency_ms')
        ->toContain('observed_at')
        ->toContain('created_at')
        ->toContain('updated_at');
});

// ─────────────────────────────────────────────────────────────────────────────
// Authentication — POST /test
// ─────────────────────────────────────────────────────────────────────────────

test('unauthenticated cannot call test endpoint', function () {
    $conn = ProviderConnection::factory()->create();

    $this->postJson("/api/provider-connections/{$conn->id}/test")->assertUnauthorized();
});

// ─────────────────────────────────────────────────────────────────────────────
// Authentication — POST /report-health
// ─────────────────────────────────────────────────────────────────────────────

test('unauthenticated cannot call report-health endpoint', function () {
    $conn = ProviderConnection::factory()->create();

    $this->postJson("/api/provider-connections/{$conn->id}/report-health")->assertUnauthorized();
});

// ─────────────────────────────────────────────────────────────────────────────
// Authorization — /test
// ─────────────────────────────────────────────────────────────────────────────

test('test endpoint returns 403 for a foreign connection', function () {
    $alice = pctUser('fb-pct-test-authz-alice');
    $bob = pctUser('fb-pct-test-authz-bob');
    $bobConn = pctHaConnection($bob);

    pctAuth($alice);

    $this->postJson("/api/provider-connections/{$bobConn->id}/test", [], pctHeaders())
        ->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// /test — provider capability gate
// ─────────────────────────────────────────────────────────────────────────────

test('test endpoint returns 422 for a device-side provider (no server-side adapter)', function () {
    $user = pctUser('fb-pct-test-cap-gh');
    $conn = pctGhConnection($user);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/test", [], pctHeaders())
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => 'Server-side testing is not available for this provider. Use the client health report endpoint.']);
});

// ─────────────────────────────────────────────────────────────────────────────
// /test — happy path (server-side, via Http::fake)
// ─────────────────────────────────────────────────────────────────────────────

test('test endpoint calls adapter testConnection and sets connected on 200', function () {
    $user = pctUser('fb-pct-test-ok');
    $conn = pctHaConnection($user);

    Http::fake([TEST_HA_BASE.'/api/' => Http::response([], 200)]);

    pctAuth($user);

    $response = $this->postJson("/api/provider-connections/{$conn->id}/test", [], pctHeaders())
        ->assertOk();

    expect($response->json('data.status'))->toBe(ConnectionStatus::Connected->value)
        ->and($response->json('data.outcome'))->toBe('success')
        ->and($response->json('data.failure_reason'))->toBeNull()
        ->and($response->json('data.last_tested_at'))->not->toBeNull();

    expect($conn->fresh()->status)->toBe(ConnectionStatus::Connected->value);
});

test('test endpoint sets unreachable_host on transport failure', function () {
    $user = pctUser('fb-pct-test-unreachable-host');
    $conn = pctHaConnection($user);

    Http::fake(fn (Request $request) => throw new ConnectionException('timeout'));

    pctAuth($user);

    $response = $this->postJson("/api/provider-connections/{$conn->id}/test", [], pctHeaders())
        ->assertOk();

    expect($response->json('data.status'))->toBe(ConnectionStatus::UnreachableHost->value)
        ->and($response->json('data.outcome'))->toBe('failure_host')
        ->and($response->json('data.failure_reason'))->toBe('Host unreachable');

    expect($conn->fresh()->status)->toBe(ConnectionStatus::UnreachableHost->value);
});

test('test endpoint sets unreachable_credentials on 401', function () {
    $user = pctUser('fb-pct-test-unreachable-creds');
    $conn = pctHaConnection($user);

    Http::fake([TEST_HA_BASE.'/api/' => Http::response([], 401)]);

    pctAuth($user);

    $response = $this->postJson("/api/provider-connections/{$conn->id}/test", [], pctHeaders())
        ->assertOk();

    expect($response->json('data.status'))->toBe(ConnectionStatus::UnreachableCredentials->value)
        ->and($response->json('data.outcome'))->toBe('failure_credentials')
        ->and($response->json('data.failure_reason'))->toBe('HTTP 401');

    expect($conn->fresh()->status)->toBe(ConnectionStatus::UnreachableCredentials->value);
});

test('test endpoint sets unreachable_credentials on 403', function () {
    $user = pctUser('fb-pct-test-403');
    $conn = pctHaConnection($user);

    Http::fake([TEST_HA_BASE.'/api/' => Http::response([], 403)]);

    pctAuth($user);

    $response = $this->postJson("/api/provider-connections/{$conn->id}/test", [], pctHeaders())
        ->assertOk();

    expect($response->json('data.status'))->toBe(ConnectionStatus::UnreachableCredentials->value);
});

test('test endpoint sets unreachable_host on 5xx', function () {
    $user = pctUser('fb-pct-test-503');
    $conn = pctHaConnection($user);

    Http::fake([TEST_HA_BASE.'/api/' => Http::response([], 503)]);

    pctAuth($user);

    $response = $this->postJson("/api/provider-connections/{$conn->id}/test", [], pctHeaders())
        ->assertOk();

    expect($response->json('data.status'))->toBe(ConnectionStatus::UnreachableHost->value)
        ->and($response->json('data.failure_reason'))->toBe('HTTP 503');
});

// ─────────────────────────────────────────────────────────────────────────────
// /test — does NOT alter devices
// ─────────────────────────────────────────────────────────────────────────────

test('test endpoint does not create or modify devices', function () {
    $user = pctUser('fb-pct-test-no-devices');
    $conn = pctHaConnection($user);

    Http::fake([TEST_HA_BASE.'/api/' => Http::response([], 200)]);

    pctAuth($user);

    $deviceCountBefore = Device::where('provider_connection_id', $conn->id)->count();

    $this->postJson("/api/provider-connections/{$conn->id}/test", [], pctHeaders())->assertOk();

    expect(Device::where('provider_connection_id', $conn->id)->count())
        ->toBe($deviceCountBefore);
});

// ─────────────────────────────────────────────────────────────────────────────
// last_tested_at vs last_synced_at separation
// ─────────────────────────────────────────────────────────────────────────────

test('test endpoint sets last_tested_at without touching last_synced_at', function () {
    $user = pctUser('fb-pct-test-timestamps');
    $conn = pctHaConnection($user);

    expect($conn->last_synced_at)->toBeNull()
        ->and($conn->last_tested_at)->toBeNull();

    Http::fake([TEST_HA_BASE.'/api/' => Http::response([], 200)]);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/test", [], pctHeaders())->assertOk();

    $fresh = $conn->fresh();
    expect($fresh->last_tested_at)->not->toBeNull()
        ->and($fresh->last_synced_at)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Attempt record persistence
// ─────────────────────────────────────────────────────────────────────────────

test('test endpoint records a connection attempt in provider_connection_attempts', function () {
    $user = pctUser('fb-pct-attempt-record');
    $conn = pctHaConnection($user);

    Http::fake([TEST_HA_BASE.'/api/' => Http::response([], 200)]);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/test", [], pctHeaders())->assertOk();

    $attempt = ProviderConnectionAttempt::where('provider_connection_id', $conn->id)->latest()->first();

    expect($attempt)->not->toBeNull()
        ->and($attempt->source)->toBe('server')
        ->and($attempt->outcome)->toBe('success')
        ->and($attempt->failure_reason)->toBeNull()
        ->and($attempt->observed_at)->not->toBeNull();
});

test('failure_reason in attempt record never contains credential values', function () {
    $user = pctUser('fb-pct-cred-safety');
    $conn = pctHaConnection($user);

    // 401 response — adapter sets error_message 'Provider returned status 401.'
    // ConnectionTestService must store only 'HTTP 401', never the raw error_message.
    Http::fake([TEST_HA_BASE.'/api/' => Http::response([], 401)]);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/test", [], pctHeaders())->assertOk();

    $attempt = ProviderConnectionAttempt::where('provider_connection_id', $conn->id)->latest()->first();

    expect($attempt->failure_reason)->toBe('HTTP 401')
        ->and($attempt->failure_reason)->not->toContain('Bearer')
        ->and($attempt->failure_reason)->not->toContain('token')
        ->and($attempt->failure_reason)->not->toContain('test-token');
});

// ─────────────────────────────────────────────────────────────────────────────
// /report-health — authorization
// ─────────────────────────────────────────────────────────────────────────────

test('report-health returns 404 for a foreign connection (scoped lookup)', function () {
    $alice = pctUser('fb-pct-rh-authz-alice');
    $bob = pctUser('fb-pct-rh-authz-bob');
    $bobConn = pctGhConnection($bob);

    pctAuth($alice);

    $this->postJson("/api/provider-connections/{$bobConn->id}/report-health", [
        'status' => ConnectionStatus::Connected->value,
    ], pctHeaders())->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// /report-health — provider capability gate
// ─────────────────────────────────────────────────────────────────────────────

test('report-health returns 422 for a server-side provider (HA has server_side_execution)', function () {
    $user = pctUser('fb-pct-rh-ha-gate');
    $conn = pctHaConnection($user);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/report-health", [
        'status' => ConnectionStatus::Connected->value,
    ], pctHeaders())->assertUnprocessable();
});

// ─────────────────────────────────────────────────────────────────────────────
// /report-health — validation
// ─────────────────────────────────────────────────────────────────────────────

test('report-health rejects connecting (system state, not client-reportable)', function () {
    $user = pctUser('fb-pct-rh-invalid-status');
    $conn = pctGhConnection($user);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/report-health", [
        'status' => ConnectionStatus::Connecting->value,
    ], pctHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('report-health rejects pending (system state, not client-reportable)', function () {
    $user = pctUser('fb-pct-rh-pending');
    $conn = pctGhConnection($user);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/report-health", [
        'status' => ConnectionStatus::Pending->value,
    ], pctHeaders())->assertUnprocessable();
});

test('report-health rejects unknown (not in client-reportable list)', function () {
    $user = pctUser('fb-pct-rh-unknown');
    $conn = pctGhConnection($user);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/report-health", [
        'status' => ConnectionStatus::Unknown->value,
    ], pctHeaders())->assertUnprocessable();
});

test('report-health rejects missing status', function () {
    $user = pctUser('fb-pct-rh-no-status');
    $conn = pctGhConnection($user);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/report-health", [], pctHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

// ─────────────────────────────────────────────────────────────────────────────
// /report-health — happy path
// ─────────────────────────────────────────────────────────────────────────────

test('report-health accepts connected status for a device-side provider and updates connection', function () {
    $user = pctUser('fb-pct-rh-ok');
    $conn = pctGhConnection($user);

    pctAuth($user);

    $response = $this->postJson("/api/provider-connections/{$conn->id}/report-health", [
        'status' => ConnectionStatus::Connected->value,
    ], pctHeaders())->assertOk();

    expect($response->json('data.status'))->toBe(ConnectionStatus::Connected->value)
        ->and($response->json('data.last_tested_at'))->not->toBeNull();

    $fresh = $conn->fresh();
    expect($fresh->status)->toBe(ConnectionStatus::Connected->value)
        ->and($fresh->last_tested_at)->not->toBeNull()
        ->and($fresh->last_synced_at)->toBeNull();
});

test('report-health accepts unreachable_host and records attempt', function () {
    $user = pctUser('fb-pct-rh-unreachable');
    $conn = pctGhConnection($user);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/report-health", [
        'status' => ConnectionStatus::UnreachableHost->value,
        'failure_reason' => 'Wi-Fi off',
    ], pctHeaders())->assertOk();

    $fresh = $conn->fresh();
    expect($fresh->status)->toBe(ConnectionStatus::UnreachableHost->value);

    $attempt = ProviderConnectionAttempt::where('provider_connection_id', $conn->id)->latest()->first();
    expect($attempt)->not->toBeNull()
        ->and($attempt->source)->toBe('client')
        ->and($attempt->outcome)->toBe('failure_host')
        ->and($attempt->failure_reason)->toBe('Wi-Fi off');
});

test('report-health accepts unreachable_credentials', function () {
    $user = pctUser('fb-pct-rh-creds');
    $conn = pctGhConnection($user);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/report-health", [
        'status' => ConnectionStatus::UnreachableCredentials->value,
    ], pctHeaders())->assertOk();

    expect($conn->fresh()->status)->toBe(ConnectionStatus::UnreachableCredentials->value);

    $attempt = ProviderConnectionAttempt::where('provider_connection_id', $conn->id)->latest()->first();
    expect($attempt->outcome)->toBe('failure_credentials');
});

test('report-health accepts revoked status', function () {
    $user = pctUser('fb-pct-rh-revoked');
    $conn = pctGhConnection($user);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/report-health", [
        'status' => ConnectionStatus::Revoked->value,
    ], pctHeaders())->assertOk();

    expect($conn->fresh()->status)->toBe(ConnectionStatus::Revoked->value);
});

test('report-health does not set last_synced_at (only last_tested_at)', function () {
    $user = pctUser('fb-pct-rh-ts-sep');
    $conn = pctGhConnection($user);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/report-health", [
        'status' => ConnectionStatus::Connected->value,
    ], pctHeaders())->assertOk();

    $fresh = $conn->fresh();
    expect($fresh->last_tested_at)->not->toBeNull()
        ->and($fresh->last_synced_at)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// ProviderConnectionResource — new fields
// ─────────────────────────────────────────────────────────────────────────────

test('ProviderConnectionResource exposes last_synced_at field', function () {
    $user = pctUser('fb-pct-resource-fields');
    $conn = pctHaConnection($user);

    pctAuth($user);

    $response = $this->getJson("/api/provider-connections/{$conn->id}", pctHeaders())
        ->assertOk();

    expect($response->json('data'))->toHaveKey('last_synced_at');
});

// ─────────────────────────────────────────────────────────────────────────────
// Sync sets last_synced_at, not last_tested_at (PRV-02 timestamp separation)
// ─────────────────────────────────────────────────────────────────────────────

test('sync sets last_synced_at but not last_tested_at', function () {
    $user = pctUser('fb-pct-sync-ts');
    $conn = pctHaConnection($user);

    Http::fake([
        TEST_HA_BASE.'/api/states' => Http::response([
            [
                'entity_id' => 'light.living_room',
                'state' => 'on',
                'attributes' => ['friendly_name' => 'Living Room'],
                'last_changed' => '2026-10-05T10:00:00+00:00',
            ],
        ], 200),
    ]);

    pctAuth($user);

    $this->postJson("/api/provider-connections/{$conn->id}/sync", [], pctHeaders())->assertOk();

    $fresh = $conn->fresh();
    expect($fresh->last_synced_at)->not->toBeNull()
        ->and($fresh->last_tested_at)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Store creates connection with pending status
// ─────────────────────────────────────────────────────────────────────────────

test('store creates a new connection with pending initial status', function () {
    $user = pctUser('fb-pct-store-pending');

    pctAuth($user);

    $response = $this->postJson('/api/provider-connections', [
        'name' => 'Test HA for PRV-02',
        'provider' => ProviderType::HomeAssistant->value,
        'config' => ['base_url' => 'https://ha.prv02.test'],
        'encrypted_credentials' => ['access_token' => 'tok'],
    ], pctHeaders())->assertCreated();

    expect($response->json('data.status'))->toBe(ConnectionStatus::Pending->value);
});
