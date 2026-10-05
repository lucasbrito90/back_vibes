<?php

declare(strict_types=1);

use App\Models\User;
use App\SmartHome\ProviderConnectionMethod;
use App\SmartHome\ProviderDescriptorRegistry;
use App\SmartHome\ProviderType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function cmJwt(User $user): UnencryptedToken
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

function cmAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(cmJwt($user)));
}

function cmHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function cmUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-cm-'.uniqid()]);
}

/**
 * ADR-045 Decision 2 — every known provider must declare at least one
 * connection method from the closed vocabulary.
 */
test('every known provider declares at least one connection method', function () {
    $registry = app(ProviderDescriptorRegistry::class);

    foreach ($registry->all() as $descriptor) {
        expect($descriptor->connectionMethods)
            ->not->toBeEmpty("Provider [{$descriptor->slug}] must declare at least one connection_method.");
    }
});

/**
 * ADR-045 Decision 2 — every declared connection method must belong to the
 * closed ProviderConnectionMethod vocabulary. Structural enforcement is in
 * ProviderDescriptor::fromConfigArray(), exercised here via the real
 * descriptor registry (which reads config at runtime).
 */
test('every declared connection method belongs to the closed vocabulary', function () {
    $registry = app(ProviderDescriptorRegistry::class);

    foreach ($registry->all() as $descriptor) {
        foreach ($descriptor->connectionMethods as $method) {
            expect(ProviderConnectionMethod::tryFrom($method->value))
                ->not->toBeNull(
                    "Provider [{$descriptor->slug}] declares unknown connection method [{$method->value}]."
                );
        }
    }
});

/**
 * ADR-045 Decision 2 — Home Assistant uses url_token (server-side credential
 * custody, ADR-045 Decision 4). Google Home uses device_sdk (device-side,
 * no backend credential, ADR-036 Decision 3/4).
 */
test('home_assistant declares url_token and google_home declares device_sdk', function () {
    $registry = app(ProviderDescriptorRegistry::class);

    $ha = $registry->forSlug(ProviderType::HomeAssistant->value);
    $gh = $registry->forSlug(ProviderType::GoogleHome->value);

    $haMethods = array_map(fn ($m) => $m->value, $ha->connectionMethods);
    $ghMethods = array_map(fn ($m) => $m->value, $gh->connectionMethods);

    expect($haMethods)->toContain(ProviderConnectionMethod::UrlToken->value)
        ->and($haMethods)->not->toContain(ProviderConnectionMethod::DeviceSdk->value);

    expect($ghMethods)->toContain(ProviderConnectionMethod::DeviceSdk->value)
        ->and($ghMethods)->not->toContain(ProviderConnectionMethod::UrlToken->value);
});

/**
 * ADR-045 Decision 2 — connection_methods is present in the API response for
 * every known provider and serializes as a non-empty JSON array.
 */
test('api response includes connection_methods for every provider', function () {
    $user = cmUser('fb-cm-api-shape');

    cmAuth($user);

    $response = $this->getJson('/api/provider-types', cmHeaders())->assertOk();

    foreach ($response->json('data') as $descriptor) {
        expect($descriptor)->toHaveKey('connection_methods')
            ->and($descriptor['connection_methods'])
            ->toBeArray()
            ->not->toBeEmpty("Provider [{$descriptor['slug']}] must expose at least one connection_method in the API response.");
    }
});

/**
 * ADR-045 Decision 2 — specific API values for the two active providers.
 */
test('api response declares correct connection methods per provider', function () {
    $user = cmUser('fb-cm-api-values');

    cmAuth($user);

    $response = $this->getJson('/api/provider-types', cmHeaders())->assertOk();
    $data = collect($response->json('data'));

    $ha = $data->firstWhere('slug', ProviderType::HomeAssistant->value);
    $gh = $data->firstWhere('slug', ProviderType::GoogleHome->value);

    expect($ha['connection_methods'])->toContain(ProviderConnectionMethod::UrlToken->value)
        ->and($ha['connection_methods'])->not->toContain(ProviderConnectionMethod::DeviceSdk->value);

    expect($gh['connection_methods'])->toContain(ProviderConnectionMethod::DeviceSdk->value)
        ->and($gh['connection_methods'])->not->toContain(ProviderConnectionMethod::UrlToken->value);
});

/**
 * Coherence guard: a descriptor with an unknown connection method value in
 * config triggers an InvalidArgumentException at registry resolution time,
 * not silently at serialization time (ADR-045 Decision 2 closed vocabulary).
 */
test('unknown connection method in config throws at descriptor resolution', function () {
    config(['smart_home.provider_descriptors.home_assistant.connection_methods' => ['not_a_real_method']]);

    expect(fn () => app(ProviderDescriptorRegistry::class)->forSlug('home_assistant'))
        ->toThrow(
            InvalidArgumentException::class,
            'Provider descriptor for [home_assistant] declares unknown connection method [not_a_real_method].'
        );
});
