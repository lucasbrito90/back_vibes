<?php

declare(strict_types=1);

use App\PushNotifications\Contracts\PushProvider as PushProviderContract;
use App\PushNotifications\Providers\FcmPushProvider;
use App\PushNotifications\PushProvider as PushProviderType;
use App\PushNotifications\PushProviderResolver;
use Tests\TestCase;

uses(TestCase::class);

test('resolves the FCM provider from a slug', function () {
    $resolver = app(PushProviderResolver::class);

    $provider = $resolver->resolve('fcm');

    expect($provider)->toBeInstanceOf(PushProviderContract::class)
        ->and($provider)->toBeInstanceOf(FcmPushProvider::class);
});

test('resolves the FCM provider from the enum case', function () {
    $resolver = app(PushProviderResolver::class);

    $provider = $resolver->resolve(PushProviderType::Fcm);

    expect($provider)->toBeInstanceOf(FcmPushProvider::class);
});

test('throws for an unsupported provider slug', function () {
    app(PushProviderResolver::class)->resolve('apns');
})->throws(InvalidArgumentException::class);

test('throws for an unknown provider slug', function () {
    app(PushProviderResolver::class)->resolve('webpush');
})->throws(InvalidArgumentException::class);

test('registers the resolver as a singleton', function () {
    $first = app(PushProviderResolver::class);
    $second = app(PushProviderResolver::class);

    expect($first)->toBe($second);
});

test('registers the FCM provider as a singleton', function () {
    $first = app(FcmPushProvider::class);
    $second = app(FcmPushProvider::class);

    expect($first)->toBe($second);
});
