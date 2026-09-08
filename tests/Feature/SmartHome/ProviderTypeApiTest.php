<?php

declare(strict_types=1);

use App\Models\User;
use App\SmartHome\Contracts\ProviderAdapter;
use App\SmartHome\ProviderAdapterRegistry;
use App\SmartHome\ProviderExecutionCapability;
use App\SmartHome\ProviderType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function ptyJwt(User $user): UnencryptedToken
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

function ptyAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(ptyJwt($user)));
}

function ptyHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function ptyUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-pt-'.uniqid()]);
}

test('unauthenticated cannot list provider types', function () {
    $this->getJson('/api/provider-types')->assertUnauthorized();
});

test('authenticated user can list registered provider types', function () {
    $user = ptyUser('fb-pt-index');

    ptyAuth($user);

    $this->getJson('/api/provider-types', ptyHeaders())
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'slug',
                    'label',
                    'config',
                    'credentials',
                    'execution_capabilities',
                ],
            ],
        ]);
});

test('response lists home_assistant with label and field schemas matching connection format', function () {
    $user = ptyUser('fb-pt-ha-shape');

    ptyAuth($user);

    $response = $this->getJson('/api/provider-types', ptyHeaders())->assertOk();

    $homeAssistant = collect($response->json('data'))
        ->firstWhere('slug', ProviderType::HomeAssistant->value);

    expect($homeAssistant)->not->toBeNull()
        ->and($homeAssistant['label'])->toBe('Home Assistant')
        ->and($homeAssistant['config']['base_url'])->toMatchArray([
            'type' => 'string',
            'format' => 'url:https',
            'required' => true,
        ])
        ->and($homeAssistant['credentials']['access_token'])->toMatchArray([
            'type' => 'string',
            'required' => true,
        ]);
});

test('reserved provider slug not registered as a known provider is absent from response', function () {
    $user = ptyUser('fb-pt-reserved');

    ptyAuth($user);

    $response = $this->getJson('/api/provider-types', ptyHeaders())->assertOk();

    $slugs = collect($response->json('data'))->pluck('slug')->all();

    expect($slugs)->toContain(ProviderType::HomeAssistant->value)
        ->and($slugs)->not->toContain(ProviderType::Tuya->value)
        ->and($slugs)->not->toContain(ProviderType::PhilipsHue->value)
        ->and($slugs)->not->toContain(ProviderType::Alexa->value)
        ->and($slugs)->not->toContain(ProviderType::Matter->value);
});

/**
 * ADR-036 Decision 3 — the Known Provider Registry (config('smart_home.known_providers'))
 * strictly SUPERSEDES the old assumption that "known provider" == "has a
 * ProviderAdapter". Every adapter slug is a known provider, but not every
 * known provider has an adapter (google_home is device-side only and has
 * no ProviderAdapter — see ADR-036 §1-3). This test proves the new
 * invariant directly, replacing the coverage removed above.
 */
test('known provider registry is a strict superset of the server-side adapter registry', function () {
    $user = ptyUser('fb-pt-known-superset');

    ptyAuth($user);

    $adapterSlugs = app(ProviderAdapterRegistry::class)->registeredSlugs();

    // Invariant: every adapter-registered slug is resolvable as a
    // ProviderAdapter — Home Assistant is unaffected by this task.
    expect($adapterSlugs)->toBe(['home_assistant'])
        ->and(app(ProviderAdapterRegistry::class)->forSlug('home_assistant'))
        ->toBeInstanceOf(ProviderAdapter::class);

    $response = $this->getJson('/api/provider-types', ptyHeaders())->assertOk();
    $slugs = collect($response->json('data'))->pluck('slug')->all();

    // google_home is a known provider (appears here) WITHOUT being in the
    // adapter registry — the superset relationship, exercised end to end.
    expect($slugs)->toContain(ProviderType::GoogleHome->value)
        ->and($adapterSlugs)->not->toContain(ProviderType::GoogleHome->value)
        ->and(fn () => app(ProviderAdapterRegistry::class)->forSlug('google_home'))
        ->toThrow(InvalidArgumentException::class, 'Unsupported smart home provider [google_home].');

    // Home Assistant still appears and is still adapter-resolvable —
    // no regression to the pre-P01 behavior for the MVP provider.
    expect($slugs)->toContain(ProviderType::HomeAssistant->value)
        ->and($adapterSlugs)->toContain(ProviderType::HomeAssistant->value);

    $googleHome = collect($response->json('data'))->firstWhere('slug', ProviderType::GoogleHome->value);

    expect($googleHome)->not->toBeNull()
        ->and($googleHome['label'])->toBe('Google Home')
        ->and($googleHome['config'])->toBe([])
        ->and($googleHome['credentials'])->toBe([]);
});

/**
 * Regression for a JSON-contract bug found in PR #31 review: an empty
 * PHP array serializes to a JSON array `[]`, but `config`/`credentials`
 * are keyed maps and must serialize as a JSON object `{}` even when
 * empty — otherwise a strongly-typed client sees a type change
 * (object -> array) the moment a provider has zero fields.
 *
 * $response->json() is NOT sufficient proof: PHP's associative json_decode
 * collapses both `{}` and `[]` to an empty array, masking the exact bug
 * this test exists to catch. This test instead inspects (a) the raw JSON
 * bytes, and (b) a non-associative decode, where `{}` becomes stdClass
 * and `[]` stays an array — the two forms are only distinguishable this way.
 */
test('empty config/credentials serialize as a JSON object, not a JSON array', function () {
    $user = ptyUser('fb-pt-empty-object-shape');

    ptyAuth($user);

    $raw = $this->getJson('/api/provider-types', ptyHeaders())->assertOk()->getContent();

    expect($raw)->not->toBeFalse();

    // (a) Raw-bytes proof: locate google_home's object slice and assert the
    // exact substrings, so no decoder can mask array vs. object.
    $googleHomeStart = strpos($raw, '"slug":"google_home"');
    expect($googleHomeStart)->not->toBeFalse('google_home must be present in the raw response.');

    $googleHomeSlice = substr($raw, $googleHomeStart, 200);

    expect($googleHomeSlice)->toContain('"config":{}')
        ->and($googleHomeSlice)->toContain('"credentials":{}')
        ->and($googleHomeSlice)->not->toContain('"config":[]')
        ->and($googleHomeSlice)->not->toContain('"credentials":[]');

    // (b) Non-associative decode proof: {} => stdClass, [] => array.
    /** @var object{data: array<int, object{slug: string, config: mixed, credentials: mixed}>} $decoded */
    $decoded = json_decode($raw, associative: false, flags: JSON_THROW_ON_ERROR);

    $googleHome = collect($decoded->data)->first(fn ($d) => $d->slug === 'google_home');

    expect($googleHome)->not->toBeNull()
        ->and($googleHome->config)->toBeInstanceOf(stdClass::class)
        ->and($googleHome->credentials)->toBeInstanceOf(stdClass::class);

    // Home Assistant is unaffected: non-empty maps still decode as objects
    // with the same keys as before this fix (keyed maps were always
    // objects when non-empty — only the empty case was broken).
    $homeAssistant = collect($decoded->data)->first(fn ($d) => $d->slug === 'home_assistant');

    expect($homeAssistant)->not->toBeNull()
        ->and($homeAssistant->config)->toBeInstanceOf(stdClass::class)
        ->and($homeAssistant->config->base_url)->not->toBeNull()
        ->and($homeAssistant->credentials)->toBeInstanceOf(stdClass::class)
        ->and($homeAssistant->credentials->access_token)->not->toBeNull();
});

/**
 * ADR-036 Decision 2 (P03) — declared execution capabilities per provider.
 * Home Assistant is the server-side/scheduled provider and declares the
 * full vocabulary except automation_delegation (reserved, unused in
 * v1.6.0). Google Home is device-side only (ADR-036 §1-3, no
 * server-reachable API) and declares neither server_side_execution nor
 * scheduled_execution.
 */
test('provider types response declares the correct execution capabilities per provider', function () {
    $user = ptyUser('fb-pt-execution-capabilities');

    ptyAuth($user);

    $response = $this->getJson('/api/provider-types', ptyHeaders())->assertOk();
    $data = collect($response->json('data'));

    $homeAssistant = $data->firstWhere('slug', ProviderType::HomeAssistant->value);
    $googleHome = $data->firstWhere('slug', ProviderType::GoogleHome->value);

    expect($homeAssistant['execution_capabilities'])->toEqualCanonicalizing([
        ProviderExecutionCapability::DeviceDiscovery->value,
        ProviderExecutionCapability::StateRead->value,
        ProviderExecutionCapability::InteractiveExecution->value,
        ProviderExecutionCapability::ServerSideExecution->value,
        ProviderExecutionCapability::ScheduledExecution->value,
    ])->and($homeAssistant['execution_capabilities'])
        ->not->toContain(ProviderExecutionCapability::AutomationDelegation->value);

    expect($googleHome['execution_capabilities'])->toEqualCanonicalizing([
        ProviderExecutionCapability::DeviceDiscovery->value,
        ProviderExecutionCapability::StateRead->value,
        ProviderExecutionCapability::InteractiveExecution->value,
    ])->and($googleHome['execution_capabilities'])
        ->not->toContain(ProviderExecutionCapability::ServerSideExecution->value)
        ->not->toContain(ProviderExecutionCapability::ScheduledExecution->value)
        ->not->toContain(ProviderExecutionCapability::AutomationDelegation->value);
});

/**
 * The vocabulary itself is closed (App\SmartHome\ProviderExecutionCapability)
 * — every value any provider declares must be one of its 6 cases. This is
 * enforced structurally by ProviderDescriptor::fromConfigArray() (throws on
 * an unknown value), exercised here through the real HTTP response rather
 * than unit-testing the DTO in isolation.
 */
test('every declared execution capability belongs to the closed vocabulary', function () {
    $user = ptyUser('fb-pt-execution-capabilities-closed');

    ptyAuth($user);

    $response = $this->getJson('/api/provider-types', ptyHeaders())->assertOk();

    foreach ($response->json('data') as $descriptor) {
        foreach ($descriptor['execution_capabilities'] as $capability) {
            expect(ProviderExecutionCapability::tryFrom($capability))
                ->not->toBeNull("Provider [{$descriptor['slug']}] declares unknown execution capability [{$capability}].");
        }
    }
});

test('response never includes credential values or example tokens', function () {
    $user = ptyUser('fb-pt-no-secrets');

    ptyAuth($user);

    $response = $this->getJson('/api/provider-types', ptyHeaders())->assertOk();

    $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('"access_token": "<')
        ->and($encoded)->not->toContain('"access_token":"')
        ->and($encoded)->not->toContain('example')
        ->and($encoded)->not->toContain('secret');

    foreach ($response->json('data') as $descriptor) {
        foreach ($descriptor['credentials'] as $field) {
            expect(array_keys($field))->toEqual(['type', 'required']);
        }
    }
});
