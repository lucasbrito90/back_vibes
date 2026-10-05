<?php

declare(strict_types=1);

use App\Models\ProviderConnection;
use App\Models\User;
use App\SmartHome\ConnectionStatus;
use App\SmartHome\ProviderType;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Schema — columns and constraints
// ─────────────────────────────────────────────────────────────────────────────

test('provider_connections table has all required columns', function () {
    $columns = Schema::getColumnListing('provider_connections');

    expect($columns)
        ->toContain('id')
        ->toContain('user_id')
        ->toContain('name')
        ->toContain('provider')
        ->toContain('config')
        ->toContain('encrypted_credentials')
        ->toContain('status')
        ->toContain('last_tested_at')
        ->toContain('last_synced_at')
        ->toContain('created_at')
        ->toContain('updated_at');
});

// ─────────────────────────────────────────────────────────────────────────────
// Model creation
// ─────────────────────────────────────────────────────────────────────────────

test('can create a provider connection via factory', function () {
    $connection = ProviderConnection::factory()->create();

    expect($connection->id)->toBeInt()
        ->and($connection->name)->toBeString()->not->toBeEmpty()
        ->and($connection->provider)->toBe(ProviderType::HomeAssistant->value)
        ->and($connection->status)->toBe(ConnectionStatus::Unknown->value);
});

test('can create a provider connection with explicit attributes', function () {
    $user = User::factory()->create();
    $connection = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'name' => 'My HA',
        'provider' => ProviderType::HomeAssistant->value,
        'config' => ['base_url' => 'https://home.example.com:8123'],
        'status' => ConnectionStatus::Connected->value,
    ]);

    $fresh = $connection->fresh();

    expect($fresh->name)->toBe('My HA')
        ->and($fresh->provider)->toBe('home_assistant')
        ->and($fresh->status)->toBe('connected')
        ->and($fresh->user_id)->toBe($user->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// Casts
// ─────────────────────────────────────────────────────────────────────────────

test('config is cast to array', function () {
    $config = ['base_url' => 'https://ha.example.test', 'port' => 8123];

    $connection = ProviderConnection::factory()->create(['config' => $config]);
    $fresh = $connection->fresh();

    expect($fresh->config)->toBe($config)
        ->and($fresh->config)->toBeArray();
});

test('last_tested_at is cast to datetime', function () {
    $connection = ProviderConnection::factory()->connected()->create();

    expect($connection->last_tested_at)->not->toBeNull()
        ->and($connection->last_tested_at)->toBeInstanceOf(Carbon::class);
});

test('last_tested_at is nullable', function () {
    $connection = ProviderConnection::factory()->create(['last_tested_at' => null]);

    expect($connection->fresh()->last_tested_at)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Credential encryption
// ─────────────────────────────────────────────────────────────────────────────

test('setEncryptedCredentials encrypts the token', function () {
    $connection = ProviderConnection::factory()->make(['encrypted_credentials' => '']);
    $connection->setEncryptedCredentials(['access_token' => 'my-secret-llat']);

    expect($connection->encrypted_credentials)
        ->toBeString()
        ->not->toContain('my-secret-llat');
});

test('decryptedCredentials returns the original access token', function () {
    $connection = ProviderConnection::factory()->make(['encrypted_credentials' => '']);
    $connection->setEncryptedCredentials(['access_token' => 'my-secret-llat']);

    $decrypted = $connection->decryptedCredentials();

    expect($decrypted)->toBe(['access_token' => 'my-secret-llat']);
});

test('encrypted value stored by factory can be decrypted', function () {
    $token = 'factory-test-token-abc123';
    $connection = ProviderConnection::factory()->create([
        'encrypted_credentials' => Crypt::encryptString(json_encode(['access_token' => $token])),
    ]);

    $fresh = $connection->fresh();
    $decrypted = $fresh->decryptedCredentials();

    expect($decrypted['access_token'])->toBe($token);
});

test('encrypted_credentials column does not contain the raw token', function () {
    $token = 'plaintext-token-xyz';
    $connection = ProviderConnection::factory()->make(['encrypted_credentials' => '']);
    $connection->setEncryptedCredentials(['access_token' => $token]);

    expect($connection->encrypted_credentials)->not->toContain($token);
});

// ─────────────────────────────────────────────────────────────────────────────
// Hidden attributes
// ─────────────────────────────────────────────────────────────────────────────

test('encrypted_credentials is hidden from toArray', function () {
    $connection = ProviderConnection::factory()->create();

    expect($connection->toArray())->not->toHaveKey('encrypted_credentials');
});

test('encrypted_credentials is hidden from JSON serialization', function () {
    $connection = ProviderConnection::factory()->create();
    $json = json_decode($connection->toJson(), true);

    expect($json)->not->toHaveKey('encrypted_credentials');
});

// ─────────────────────────────────────────────────────────────────────────────
// Unique constraint
// ─────────────────────────────────────────────────────────────────────────────

test('unique constraint prevents two connections same user and name', function () {
    $user = User::factory()->create();

    ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home HA',
    ]);

    expect(fn () => ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home HA',
    ]))->toThrow(QueryException::class);
});

test('same user can have two home_assistant connections with different names', function () {
    $user = User::factory()->create();

    $home = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home HA',
        'provider' => ProviderType::HomeAssistant->value,
    ]);

    $office = ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'name' => 'Office HA',
        'provider' => ProviderType::HomeAssistant->value,
    ]);

    expect($home->id)->not->toBe($office->id)
        ->and(ProviderConnection::where('user_id', $user->id)->count())->toBe(2);
});

test('different users can each have a home_assistant connection', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $connA = ProviderConnection::factory()->create([
        'user_id' => $userA->id,
        'provider' => ProviderType::HomeAssistant->value,
    ]);

    $connB = ProviderConnection::factory()->create([
        'user_id' => $userB->id,
        'provider' => ProviderType::HomeAssistant->value,
    ]);

    expect($connA->id)->not->toBe($connB->id)
        ->and(ProviderConnection::query()->count())->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// Relationships
// ─────────────────────────────────────────────────────────────────────────────

test('user relationship returns the owning user', function () {
    $user = User::factory()->create();
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);

    expect($connection->user->id)->toBe($user->id);
});

test('providerConnections relationship on User returns collection', function () {
    $user = User::factory()->create();
    ProviderConnection::factory()->count(1)->create(['user_id' => $user->id]);

    $connections = $user->providerConnections;

    expect($connections)->toHaveCount(1)
        ->and($connections->first())->toBeInstanceOf(ProviderConnection::class);
});

test('devices relationship is a HasMany relationship object', function () {
    // The devices table does not yet have provider_connection_id (Phase 4 hardening).
    // This test verifies the relationship is declared correctly without executing
    // the query — the FK column lands in Phase 4.
    $connection = ProviderConnection::factory()->make();

    $relation = $connection->devices();

    expect($relation)->toBeInstanceOf(HasMany::class);
});

// ─────────────────────────────────────────────────────────────────────────────
// ProviderType enum
// ─────────────────────────────────────────────────────────────────────────────

/**
 * ADR-045 Decision 1 — ProviderType is a typed-alias layer only; the
 * canonical identity source is config('smart_home.known_providers') via
 * ProviderDescriptorRegistry. Active provider cases must match known_providers.
 */
test('ProviderType active cases align with known_providers config', function () {
    /** @var list<string> $knownSlugs */
    $knownSlugs = config('smart_home.known_providers', []);

    expect($knownSlugs)->toContain(ProviderType::HomeAssistant->value)
        ->and($knownSlugs)->toContain(ProviderType::GoogleHome->value);
});

test('ProviderType reserved cases are absent from known_providers config', function () {
    /** @var list<string> $knownSlugs */
    $knownSlugs = config('smart_home.known_providers', []);

    expect($knownSlugs)->not->toContain(ProviderType::Tuya->value)
        ->and($knownSlugs)->not->toContain(ProviderType::PhilipsHue->value)
        ->and($knownSlugs)->not->toContain(ProviderType::Alexa->value)
        ->and($knownSlugs)->not->toContain(ProviderType::Matter->value);
});

// ─────────────────────────────────────────────────────────────────────────────
// ConnectionStatus enum
// ─────────────────────────────────────────────────────────────────────────────

test('ConnectionStatus values returns all status strings including PRV-02 additions', function () {
    $values = ConnectionStatus::values();

    // Legacy values (kept for backward compat with existing data and mobile clients)
    expect($values)->toContain('connected')
        ->toContain('unreachable')
        ->toContain('unknown');

    // PRV-02 additions (ADR-045 Decision 5)
    expect($values)->toContain('pending')
        ->toContain('connecting')
        ->toContain('unreachable_host')
        ->toContain('unreachable_credentials')
        ->toContain('revoked')
        ->toHaveCount(8);
});

// ─────────────────────────────────────────────────────────────────────────────
// Factory states
// ─────────────────────────────────────────────────────────────────────────────

test('factory connected state sets status and last_tested_at', function () {
    $connection = ProviderConnection::factory()->connected()->create();

    expect($connection->status)->toBe(ConnectionStatus::Connected->value)
        ->and($connection->last_tested_at)->not->toBeNull();
});

test('factory unreachable state sets status and last_tested_at', function () {
    $connection = ProviderConnection::factory()->unreachable()->create();

    expect($connection->status)->toBe(ConnectionStatus::Unreachable->value)
        ->and($connection->last_tested_at)->not->toBeNull();
});

test('factory default state has unknown status and null last_tested_at', function () {
    $connection = ProviderConnection::factory()->create();

    expect($connection->status)->toBe(ConnectionStatus::Unknown->value)
        ->and($connection->last_tested_at)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Nullable encrypted_credentials (ADR-036 Decision 4 / P02)
//
// A provider without server_side_execution (e.g. google_home) has no
// credential for the server to hold. These tests exercise the model layer
// directly, not the HTTP API — StoreProviderConnectionRequest still gates
// `provider` against ProviderAdapterRegistry::registeredSlugs() (only
// home_assistant today), so no HTTP path can create a null-credential
// connection yet. This task only removes the structural restriction.
// ─────────────────────────────────────────────────────────────────────────────

test('a provider connection can be persisted with encrypted_credentials null', function () {
    $connection = ProviderConnection::factory()->create([
        'name' => 'My Google Home',
        'provider' => ProviderType::GoogleHome->value,
        'config' => [],
        'encrypted_credentials' => null,
    ]);

    $fresh = $connection->fresh();

    expect($fresh)->not->toBeNull()
        ->and($fresh->encrypted_credentials)->toBeNull();
});

test('decryptedCredentials returns null when encrypted_credentials is null', function () {
    $connection = ProviderConnection::factory()->create([
        'name' => 'My Google Home',
        'provider' => ProviderType::GoogleHome->value,
        'config' => [],
        'encrypted_credentials' => null,
    ]);

    expect($connection->fresh()->decryptedCredentials())->toBeNull();
});

test('encrypted_credentials null is still hidden from toArray and JSON serialization', function () {
    $connection = ProviderConnection::factory()->create([
        'name' => 'My Google Home',
        'provider' => ProviderType::GoogleHome->value,
        'config' => [],
        'encrypted_credentials' => null,
    ]);

    $json = json_decode($connection->toJson(), true);

    expect($connection->toArray())->not->toHaveKey('encrypted_credentials')
        ->and($json)->not->toHaveKey('encrypted_credentials');
});

test('home_assistant connections created via factory default still require and store encrypted credentials', function () {
    $connection = ProviderConnection::factory()->create();

    expect($connection->provider)->toBe(ProviderType::HomeAssistant->value)
        ->and($connection->encrypted_credentials)->not->toBeNull()
        ->and($connection->fresh()->decryptedCredentials())->toBeArray()
        ->and($connection->fresh()->decryptedCredentials())->toHaveKey('access_token');
});
