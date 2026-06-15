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

test('unique constraint prevents two connections same user and provider', function () {
    $user = User::factory()->create();

    ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => ProviderType::HomeAssistant->value,
    ]);

    expect(fn () => ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => ProviderType::HomeAssistant->value,
    ]))->toThrow(QueryException::class);
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

test('ProviderType mvpAllowed returns only home_assistant', function () {
    $allowed = ProviderType::mvpAllowed();

    expect($allowed)->toHaveCount(1)
        ->and($allowed[0])->toBe(ProviderType::HomeAssistant)
        ->and($allowed[0]->value)->toBe('home_assistant');
});

test('ProviderType home_assistant isMvpSupported returns true', function () {
    expect(ProviderType::HomeAssistant->isMvpSupported())->toBeTrue();
});

test('ProviderType reserved slugs are not in mvpAllowed', function () {
    $allowed = ProviderType::mvpAllowed();

    expect($allowed)->not->toContain(ProviderType::Tuya)
        ->and($allowed)->not->toContain(ProviderType::PhilipsHue)
        ->and($allowed)->not->toContain(ProviderType::Alexa)
        ->and($allowed)->not->toContain(ProviderType::GoogleHome)
        ->and($allowed)->not->toContain(ProviderType::Matter);
});

test('ProviderType reserved slugs are not MVP supported', function () {
    expect(ProviderType::Tuya->isMvpSupported())->toBeFalse()
        ->and(ProviderType::PhilipsHue->isMvpSupported())->toBeFalse()
        ->and(ProviderType::Alexa->isMvpSupported())->toBeFalse()
        ->and(ProviderType::GoogleHome->isMvpSupported())->toBeFalse()
        ->and(ProviderType::Matter->isMvpSupported())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// ConnectionStatus enum
// ─────────────────────────────────────────────────────────────────────────────

test('ConnectionStatus values returns all three status strings', function () {
    $values = ConnectionStatus::values();

    expect($values)->toContain('connected')
        ->toContain('unreachable')
        ->toContain('unknown')
        ->toHaveCount(3);
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
