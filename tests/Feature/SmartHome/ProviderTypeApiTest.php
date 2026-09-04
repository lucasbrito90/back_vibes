<?php

declare(strict_types=1);

use App\Models\User;
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

test('reserved provider slug not registered in adapter registry is absent from response', function () {
    $user = ptyUser('fb-pt-reserved');

    ptyAuth($user);

    $response = $this->getJson('/api/provider-types', ptyHeaders())->assertOk();

    $slugs = collect($response->json('data'))->pluck('slug')->all();

    expect($slugs)->toContain(ProviderType::HomeAssistant->value)
        ->and($slugs)->not->toContain(ProviderType::Tuya->value)
        ->and($slugs)->not->toContain(ProviderType::PhilipsHue->value)
        ->and($slugs)->not->toContain(ProviderType::Alexa->value)
        ->and($slugs)->not->toContain(ProviderType::GoogleHome->value)
        ->and($slugs)->not->toContain(ProviderType::Matter->value);
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
