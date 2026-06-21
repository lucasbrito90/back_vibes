<?php

declare(strict_types=1);

use App\Models\ProviderConnection;
use App\Models\User;
use App\SmartHome\ConnectionStatus;
use App\SmartHome\ProviderType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function pcJwt(User $user): UnencryptedToken
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

function pcAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(pcJwt($user)));
}

function pcHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function pcUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-pc-'.uniqid()]);
}

function validConnectionPayload(): array
{
    return [
        'name' => 'My Home HA',
        'provider' => ProviderType::HomeAssistant->value,
        'config' => ['base_url' => 'https://ha.example.test'],
        'encrypted_credentials' => ['access_token' => 'my-secret-token'],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Authentication
// ─────────────────────────────────────────────────────────────────────────────

test('unauthenticated cannot list provider connections', function () {
    $this->getJson('/api/provider-connections')->assertUnauthorized();
});

test('unauthenticated cannot create provider connection', function () {
    $this->postJson('/api/provider-connections', validConnectionPayload())->assertUnauthorized();
});

test('unauthenticated cannot show provider connection', function () {
    $conn = ProviderConnection::factory()->create();
    $this->getJson("/api/provider-connections/{$conn->id}")->assertUnauthorized();
});

test('unauthenticated cannot update provider connection', function () {
    $conn = ProviderConnection::factory()->create();
    $this->patchJson("/api/provider-connections/{$conn->id}", ['name' => 'x'])->assertUnauthorized();
});

test('unauthenticated cannot delete provider connection', function () {
    $conn = ProviderConnection::factory()->create();
    $this->deleteJson("/api/provider-connections/{$conn->id}")->assertUnauthorized();
});

// ─────────────────────────────────────────────────────────────────────────────
// Index
// ─────────────────────────────────────────────────────────────────────────────

test('index returns only own connections', function () {
    $alice = pcUser('fb-pc-idx-alice');
    $bob = pcUser('fb-pc-idx-bob');

    $mine = ProviderConnection::factory()->create(['user_id' => $alice->id, 'name' => 'Alice HA']);
    ProviderConnection::factory()->create(['user_id' => $bob->id, 'name' => 'Bob HA']);

    pcAuth($alice);

    $response = $this->getJson('/api/provider-connections', pcHeaders())->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$mine->id])
        ->and($response->json('data.0.name'))->toBe('Alice HA');
});

test('index returns empty array when user has no connections', function () {
    $user = pcUser('fb-pc-idx-empty');

    pcAuth($user);

    $this->getJson('/api/provider-connections', pcHeaders())
        ->assertOk()
        ->assertJsonPath('data', []);
});

// ─────────────────────────────────────────────────────────────────────────────
// Store
// ─────────────────────────────────────────────────────────────────────────────

test('user can create provider connection', function () {
    $user = pcUser('fb-pc-store-ok');

    pcAuth($user);

    $response = $this->postJson('/api/provider-connections', validConnectionPayload(), pcHeaders())
        ->assertCreated();

    expect($response->json('data.name'))->toBe('My Home HA')
        ->and($response->json('data.provider'))->toBe(ProviderType::HomeAssistant->value)
        ->and($response->json('data.config.base_url'))->toBe('https://ha.example.test');
});

test('store forces user_id from auth and ignores request value', function () {
    $alice = pcUser('fb-pc-store-uid-alice');
    $bob = pcUser('fb-pc-store-uid-bob');

    pcAuth($alice);

    $response = $this->postJson('/api/provider-connections', [
        ...validConnectionPayload(),
        'user_id' => $bob->id,
    ], pcHeaders())->assertCreated();

    $conn = ProviderConnection::findOrFail($response->json('data.id'));
    expect($conn->user_id)->toBe($alice->id);
});

test('store encrypts access_token and stores it', function () {
    $user = pcUser('fb-pc-store-encrypt');

    pcAuth($user);

    $response = $this->postJson('/api/provider-connections', [
        ...validConnectionPayload(),
        'encrypted_credentials' => ['access_token' => 'super-secret-token'],
    ], pcHeaders())->assertCreated();

    $conn = ProviderConnection::findOrFail($response->json('data.id'));

    $decrypted = $conn->decryptedCredentials();
    expect($decrypted['access_token'])->toBe('super-secret-token')
        ->and($conn->getRawOriginal('encrypted_credentials'))->not->toBe('super-secret-token');
});

test('store rejects prohibited status field', function () {
    $user = pcUser('fb-pc-store-status');

    pcAuth($user);

    $this->postJson('/api/provider-connections', [
        ...validConnectionPayload(),
        'status' => ConnectionStatus::Connected->value,
    ], pcHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

test('store rejects prohibited last_tested_at field', function () {
    $user = pcUser('fb-pc-store-lta');

    pcAuth($user);

    $this->postJson('/api/provider-connections', [
        ...validConnectionPayload(),
        'last_tested_at' => now()->toISOString(),
    ], pcHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['last_tested_at']);
});

test('store rejects invalid provider', function () {
    $user = pcUser('fb-pc-store-prov');

    pcAuth($user);

    $this->postJson('/api/provider-connections', [
        ...validConnectionPayload(),
        'provider' => 'alexa',
    ], pcHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['provider']);
});

test('store rejects non-https base_url', function () {
    $user = pcUser('fb-pc-store-http');

    pcAuth($user);

    $this->postJson('/api/provider-connections', [
        ...validConnectionPayload(),
        'config' => ['base_url' => 'http://ha.example.test'],
    ], pcHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['config.base_url']);
});

test('store rejects missing required fields', function () {
    $user = pcUser('fb-pc-store-missing');

    pcAuth($user);

    $this->postJson('/api/provider-connections', [], pcHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'provider', 'config', 'encrypted_credentials']);
});

test('same user cannot create duplicate provider connection', function () {
    $user = pcUser('fb-pc-store-dup');

    ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => ProviderType::HomeAssistant->value,
    ]);

    pcAuth($user);

    $this->withoutExceptionHandling();

    $this->postJson('/api/provider-connections', validConnectionPayload(), pcHeaders());
})->throws(QueryException::class);

test('different users can each create a home assistant connection', function () {
    $alice = pcUser('fb-pc-store-diff-alice');
    $bob = pcUser('fb-pc-store-diff-bob');

    ProviderConnection::factory()->create(['user_id' => $alice->id]);

    pcAuth($bob);

    $this->postJson('/api/provider-connections', validConnectionPayload(), pcHeaders())
        ->assertCreated();

    expect(ProviderConnection::count())->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// Show
// ─────────────────────────────────────────────────────────────────────────────

test('user can show own connection', function () {
    $user = pcUser('fb-pc-show-own');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id, 'name' => 'Mine']);

    pcAuth($user);

    $this->getJson("/api/provider-connections/{$conn->id}", pcHeaders())
        ->assertOk()
        ->assertJsonPath('data.id', $conn->id)
        ->assertJsonPath('data.name', 'Mine');
});

test('user cannot show another users connection', function () {
    $alice = pcUser('fb-pc-show-alice');
    $bob = pcUser('fb-pc-show-bob');

    $bobConn = ProviderConnection::factory()->create(['user_id' => $bob->id]);

    pcAuth($alice);

    $this->getJson("/api/provider-connections/{$bobConn->id}", pcHeaders())->assertForbidden();
});

// ─────────────────────────────────────────────────────────────────────────────
// Resource shape — security
// ─────────────────────────────────────────────────────────────────────────────

test('resource never exposes encrypted_credentials', function () {
    $user = pcUser('fb-pc-res-nocred');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);

    pcAuth($user);

    $response = $this->getJson("/api/provider-connections/{$conn->id}", pcHeaders())->assertOk();

    $data = $response->json('data');

    expect($data)->not->toHaveKey('encrypted_credentials')
        ->and($data)->not->toHaveKey('access_token');
});

test('ProviderConnectionResource returns all expected fields', function () {
    $user = pcUser('fb-pc-res-shape');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);

    pcAuth($user);

    $this->getJson("/api/provider-connections/{$conn->id}", pcHeaders())
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'provider',
                'config',
                'status',
                'last_tested_at',
                'created_at',
                'updated_at',
            ],
        ]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Update
// ─────────────────────────────────────────────────────────────────────────────

test('user can update own connection name', function () {
    $user = pcUser('fb-pc-upd-name');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id, 'name' => 'Before']);

    pcAuth($user);

    $this->patchJson("/api/provider-connections/{$conn->id}", ['name' => 'After'], pcHeaders())
        ->assertOk()
        ->assertJsonPath('data.name', 'After');

    expect($conn->fresh()->name)->toBe('After');
});

test('update allows partial fields', function () {
    $user = pcUser('fb-pc-upd-partial');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id, 'name' => 'Partial']);

    pcAuth($user);

    $this->patchJson("/api/provider-connections/{$conn->id}", [
        'config' => ['base_url' => 'https://new.example.test'],
    ], pcHeaders())
        ->assertOk()
        ->assertJsonPath('data.config.base_url', 'https://new.example.test')
        ->assertJsonPath('data.name', 'Partial');
});

test('update can refresh encrypted_credentials', function () {
    $user = pcUser('fb-pc-upd-cred');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);

    pcAuth($user);

    $this->patchJson("/api/provider-connections/{$conn->id}", [
        'encrypted_credentials' => ['access_token' => 'new-token-xyz'],
    ], pcHeaders())->assertOk();

    $fresh = $conn->fresh();
    expect($fresh->decryptedCredentials()['access_token'])->toBe('new-token-xyz');
});

test('user cannot update another users connection', function () {
    $alice = pcUser('fb-pc-upd-xuser-alice');
    $bob = pcUser('fb-pc-upd-xuser-bob');

    $bobConn = ProviderConnection::factory()->create(['user_id' => $bob->id, 'name' => 'Original']);

    pcAuth($alice);

    $this->patchJson("/api/provider-connections/{$bobConn->id}", ['name' => 'Stolen'], pcHeaders())
        ->assertForbidden();

    expect($bobConn->fresh()->name)->toBe('Original');
});

test('update rejects prohibited status field', function () {
    $user = pcUser('fb-pc-upd-status');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);

    pcAuth($user);

    $this->patchJson("/api/provider-connections/{$conn->id}", [
        'status' => ConnectionStatus::Connected->value,
    ], pcHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

// ─────────────────────────────────────────────────────────────────────────────
// Destroy
// ─────────────────────────────────────────────────────────────────────────────

test('user can delete own connection', function () {
    $user = pcUser('fb-pc-del-own');
    $conn = ProviderConnection::factory()->create(['user_id' => $user->id]);

    pcAuth($user);

    $this->deleteJson("/api/provider-connections/{$conn->id}", [], pcHeaders())
        ->assertNoContent();

    expect(ProviderConnection::find($conn->id))->toBeNull();
});

test('user cannot delete another users connection', function () {
    $alice = pcUser('fb-pc-del-alice');
    $bob = pcUser('fb-pc-del-bob');

    $bobConn = ProviderConnection::factory()->create(['user_id' => $bob->id]);

    pcAuth($alice);

    $this->deleteJson("/api/provider-connections/{$bobConn->id}", [], pcHeaders())
        ->assertForbidden();

    expect(ProviderConnection::find($bobConn->id))->not->toBeNull();
});
