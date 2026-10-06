<?php

declare(strict_types=1);

use App\Http\Resources\SceneActionResource;
use App\Http\Resources\ScheduleResource;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\SceneAction;
use App\Models\Schedule;
use App\Models\User;
use App\SmartHome\Canonical\CanonicalCapabilitiesDocument;
use App\SmartHome\Canonical\Capability;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\ContractVersion;
use App\SmartHome\DeviceStateFreshness;
use App\SmartHome\ProviderType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

/**
 * Empty maps must serialize as JSON objects, not arrays.
 *
 * A field whose PHP value is a string-keyed array encodes as `{...}` when it
 * has entries and as `[]` when it does not, because `json_encode` cannot tell
 * an empty map from an empty list. The wire type therefore changed with
 * cardinality, and a client typing the field as a map broke on exactly the
 * empty case.
 *
 * Every assertion here inspects the RAW response body. `assertJsonPath` cannot
 * express this: PHP decodes `[]` and `{}` to the same value, so
 * `json_decode('[]', true) === json_decode('{}', true)` is true and any
 * path-based assertion passes for both shapes. That blindness is why the
 * defect shipped — see the first test, which pins it.
 */

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function emsAuth(User $user): void
{
    $dataset = new DataSet([
        'sub' => $user->firebase_uid,
        'email' => $user->email,
        'name' => $user->name,
    ], 'e30.');

    $jwt = Mockery::mock(UnencryptedToken::class);
    $jwt->shouldReceive('claims')->andReturn($dataset);

    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn($jwt));
}

function emsUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-ems-'.uniqid()]);
}

function emsConnection(User $user): ProviderConnection
{
    return ProviderConnection::factory()->create([
        'user_id' => $user->id,
        'provider' => ProviderType::GoogleHome->value,
        'config' => [],
        'encrypted_credentials' => null,
    ]);
}

/** @param array<string, mixed> $attributes */
function emsDevice(User $user, ProviderConnection $conn, array $attributes = []): Device
{
    return Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $conn->id,
        'provider' => $conn->provider,
        ...$attributes,
    ]);
}

function emsGet(string $url): string
{
    return test()->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson($url)
        ->assertOk()
        ->getContent();
}

// ─────────────────────────────────────────────────────────────────────────────
// Why assertJsonPath cannot guard this
// ─────────────────────────────────────────────────────────────────────────────

test('a path assertion cannot distinguish an empty array from an empty object', function () {
    // The reason this regression reached production despite the BFF contract
    // test asserting `data.state.values` was `[]`: that assertion passes either
    // way. Every test below therefore reads the raw body instead.
    expect(json_decode('[]', true))->toBe(json_decode('{}', true));
});

// ─────────────────────────────────────────────────────────────────────────────
// DeviceStateSnapshot.values — the reported defect
// ─────────────────────────────────────────────────────────────────────────────

test('device detail serializes empty state values as an object', function () {
    $user = emsUser('fb-ems-values-empty');
    $device = emsDevice($user, emsConnection($user), ['state' => null, 'state_read_at' => null]);

    emsAuth($user);

    $body = emsGet("/api/devices/{$device->id}");

    expect($body)->toContain('"values":{}')
        ->and($body)->not->toContain('"values":[]');
});

test('populated state values are preserved unchanged', function () {
    // State is validated against the device's declared capabilities, so both
    // must be declared or the snapshot degrades to unknown. Read through the
    // list endpoint, which does not refresh from the provider.
    $user = emsUser('fb-ems-values-full');
    $stored = (new CanonicalCapabilitiesDocument(
        ContractVersion::CURRENT,
        [
            'power' => Capability::fromCatalog(CapabilityId::Power),
            'brightness' => Capability::fromCatalog(CapabilityId::Brightness),
        ],
    ))->toArray();

    emsDevice($user, emsConnection($user), [
        'capabilities' => $stored,
        'state' => ['power' => true, 'brightness' => 65],
        'state_read_at' => now(),
    ]);

    emsAuth($user);

    $body = emsGet('/api/devices');

    expect($body)->toContain('"power":true')
        ->and($body)->toContain('"brightness":65')
        ->and($body)->not->toContain('"values":[]')
        ->and($body)->not->toContain('"values":{}');
});

test('the device list serializes empty state values as an object too', function () {
    // index() and show() share DeviceStateSnapshot, so both had the defect.
    $user = emsUser('fb-ems-values-index');
    emsDevice($user, emsConnection($user), ['state' => null, 'state_read_at' => null]);

    emsAuth($user);

    $body = emsGet('/api/devices');

    expect($body)->toContain('"values":{}')
        ->and($body)->not->toContain('"values":[]');
});

test('freshness and read_at are unaffected by the values fix', function () {
    $user = emsUser('fb-ems-values-siblings');
    $device = emsDevice($user, emsConnection($user), ['state' => null, 'state_read_at' => null]);

    emsAuth($user);

    test()->withHeaders(['Authorization' => 'Bearer tok'])
        ->getJson("/api/devices/{$device->id}")
        ->assertOk()
        ->assertJsonPath('data.state.freshness', DeviceStateFreshness::Unknown->value)
        ->assertJsonPath('data.state.read_at', null);
});

// ─────────────────────────────────────────────────────────────────────────────
// DeviceResource.metadata — reachable: ReportedDeviceSyncService stores []
// ─────────────────────────────────────────────────────────────────────────────

test('device metadata serializes an empty map as an object', function () {
    $user = emsUser('fb-ems-metadata-empty');
    emsDevice($user, emsConnection($user), ['metadata' => []]);

    emsAuth($user);

    $body = emsGet('/api/devices');

    expect($body)->toContain('"metadata":{}')
        ->and($body)->not->toContain('"metadata":[]');
});

test('device metadata keeps null distinct from an empty map', function () {
    // null means "no metadata recorded"; {} means "recorded, and empty".
    $user = emsUser('fb-ems-metadata-null');
    emsDevice($user, emsConnection($user), ['metadata' => null]);

    emsAuth($user);

    $body = emsGet('/api/devices');

    expect($body)->toContain('"metadata":null')
        ->and($body)->not->toContain('"metadata":{}');
});

test('device metadata preserves populated values unchanged', function () {
    $user = emsUser('fb-ems-metadata-full');
    emsDevice($user, emsConnection($user), ['metadata' => ['model' => 'LED-9W']]);

    emsAuth($user);

    expect(emsGet('/api/devices'))->toContain('"metadata":{"model":"LED-9W"}');
});

// ─────────────────────────────────────────────────────────────────────────────
// DeviceResource.capabilities — null (unknown) must stay distinct from {} (none)
// ─────────────────────────────────────────────────────────────────────────────

test('device capabilities serialize an empty map as an object', function () {
    $user = emsUser('fb-ems-caps-empty');
    emsDevice($user, emsConnection($user), ['capabilities' => []]);

    emsAuth($user);

    $body = emsGet('/api/devices');

    expect($body)->toContain('"capabilities":{}')
        ->and($body)->not->toContain('"capabilities":[]');
});

test('device capabilities keep null meaning unknown', function () {
    // This distinction is load-bearing on the client: null fails open (offer
    // every action, because capabilities were never derived) while {} means the
    // device genuinely declares none and must offer nothing. Emitting [] for
    // the latter made a client read "declares none" as "unknown".
    $user = emsUser('fb-ems-caps-null');
    emsDevice($user, emsConnection($user), ['capabilities' => null]);

    emsAuth($user);

    $body = emsGet('/api/devices');

    expect($body)->toContain('"capabilities":null')
        ->and($body)->not->toContain('"capabilities":{}');
});

test('device capabilities preserve a canonical envelope unchanged', function () {
    $user = emsUser('fb-ems-caps-full');
    $stored = (new CanonicalCapabilitiesDocument(
        ContractVersion::CURRENT,
        ['power' => Capability::fromCatalog(CapabilityId::Power)],
    ))->toArray();

    emsDevice($user, emsConnection($user), ['capabilities' => $stored]);

    emsAuth($user);

    $body = emsGet('/api/devices');

    expect($body)->toContain('"contract_version"')
        ->and($body)->toContain('"power"')
        ->and($body)->not->toContain('"capabilities":[]');
});

// ─────────────────────────────────────────────────────────────────────────────
// SceneActionResource.parameters / ScheduleResource.recurrence_config
// ─────────────────────────────────────────────────────────────────────────────

test('scene action parameters serialize an empty map as an object', function () {
    $json = json_encode((new SceneActionResource(new SceneAction(['parameters' => []])))->toArray(request()));

    expect($json)->toContain('"parameters":{}')
        ->and($json)->not->toContain('"parameters":[]');
});

test('scene action parameters keep null and populated values unchanged', function () {
    $nullJson = json_encode((new SceneActionResource(new SceneAction(['parameters' => null])))->toArray(request()));
    $fullJson = json_encode((new SceneActionResource(new SceneAction(['parameters' => ['value' => 40]])))->toArray(request()));

    expect($nullJson)->toContain('"parameters":null')
        ->and($fullJson)->toContain('"parameters":{"value":40}');
});

test('schedule recurrence_config serializes an empty map as an object', function () {
    $json = json_encode((new ScheduleResource(new Schedule(['recurrence_config' => []])))->toArray(request()));

    expect($json)->toContain('"recurrence_config":{}')
        ->and($json)->not->toContain('"recurrence_config":[]');
});

test('schedule recurrence_config keeps null and populated values unchanged', function () {
    $nullJson = json_encode((new ScheduleResource(new Schedule(['recurrence_config' => null])))->toArray(request()));
    $fullJson = json_encode((new ScheduleResource(new Schedule(['recurrence_config' => ['days' => [1, 2]]])))->toArray(request()));

    expect($nullJson)->toContain('"recurrence_config":null')
        ->and($fullJson)->toContain('"recurrence_config":{"days":[1,2]}');
});
