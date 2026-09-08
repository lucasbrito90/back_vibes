<?php

declare(strict_types=1);

use App\SmartHome\ProviderRequestTimeout;
use Tests\TestCase;

uses(TestCase::class);

test('uses the home_assistant timeout when the provider has an explicit entry', function () {
    config(['smart_home.providers.home_assistant.timeout' => 12]);

    expect(ProviderRequestTimeout::forSlug('home_assistant'))->toBe(12);
});

test('falls back to the home_assistant timeout when a provider has no explicit entry', function () {
    config([
        'smart_home.providers' => [
            'home_assistant' => ['timeout' => 15],
        ],
    ]);

    expect(ProviderRequestTimeout::forSlug('future_provider'))->toBe(15);
});

test('uses a provider-specific timeout when configured', function () {
    config([
        'smart_home.providers.home_assistant.timeout' => 10,
        'smart_home.providers.future_provider.timeout' => 25,
    ]);

    expect(ProviderRequestTimeout::forSlug('future_provider'))->toBe(25);
});
