<?php

declare(strict_types=1);

use App\SmartHome\Adapters\HomeAssistantAdapter;
use App\SmartHome\Contracts\ProviderAdapter;
use App\SmartHome\ProviderAdapterResolver;
use App\SmartHome\ProviderType;
use Tests\TestCase;

uses(TestCase::class);

function makeResolver(): ProviderAdapterResolver
{
    return new ProviderAdapterResolver(new HomeAssistantAdapter);
}

test('resolves home_assistant by string slug', function () {
    $adapter = makeResolver()->forProvider('home_assistant');

    expect($adapter)->toBeInstanceOf(HomeAssistantAdapter::class)
        ->and($adapter)->toBeInstanceOf(ProviderAdapter::class);
});

test('resolves home_assistant by ProviderType enum', function () {
    $adapter = makeResolver()->forProvider(ProviderType::HomeAssistant);

    expect($adapter)->toBeInstanceOf(HomeAssistantAdapter::class);
});

test('rejects an unknown provider slug', function () {
    expect(fn () => makeResolver()->forProvider('not_a_provider'))
        ->toThrow(InvalidArgumentException::class);
});

test('rejects a reserved future provider enum', function () {
    expect(fn () => makeResolver()->forProvider(ProviderType::Tuya))
        ->toThrow(InvalidArgumentException::class);
});

test('resolver is bound as a singleton in the container', function () {
    $a = app(ProviderAdapterResolver::class);
    $b = app(ProviderAdapterResolver::class);

    expect($a)->toBe($b)
        ->and($a->forProvider(ProviderType::HomeAssistant))->toBeInstanceOf(HomeAssistantAdapter::class);
});

test('HomeAssistantAdapter is bound as a singleton in the container', function () {
    expect(app(HomeAssistantAdapter::class))->toBe(app(HomeAssistantAdapter::class));
});
