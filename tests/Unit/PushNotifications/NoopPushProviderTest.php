<?php

declare(strict_types=1);

use App\Models\PushToken;
use App\PushNotifications\Contracts\PushProvider as PushProviderContract;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\DTOs\PushResult;
use App\PushNotifications\Providers\NoopPushProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

const NOOP_DEVICE_TOKEN = 'noop-device-token-ABCDEF0123456789-long-enough-for-preview';

function noopToken(string $token = NOOP_DEVICE_TOKEN): PushToken
{
    $model = new PushToken;
    $model->token = $token;
    $model->platform = 'android';
    $model->provider = 'fcm';

    return $model;
}

function noopPayload(): NotificationPayload
{
    return new NotificationPayload(
        title: 'Dry run',
        body: 'No transport contacted.',
        data: ['type' => 'account_security_notice'],
    );
}

test('implements the PushProvider contract', function () {
    expect(new NoopPushProvider)->toBeInstanceOf(PushProviderContract::class);
});

test('returns a successful PushResult with provider noop and null messageId', function () {
    $result = (new NoopPushProvider)->send(noopToken(), noopPayload());

    expect($result)->toBeInstanceOf(PushResult::class)
        ->and($result->success)->toBeTrue()
        ->and($result->provider)->toBe('noop')
        ->and($result->statusCode)->toBeNull()
        ->and($result->messageId)->toBeNull()
        ->and($result->errorCode)->toBeNull()
        ->and($result->errorMessage)->toBeNull();
});

test('makes no HTTP calls', function () {
    Http::fake();

    (new NoopPushProvider)->send(noopToken(), noopPayload());

    Http::assertNothingSent();
});

test('exposes a tokenPreview but never the full device token', function () {
    $result = (new NoopPushProvider)->send(noopToken(), noopPayload());

    expect($result->tokenPreview)->not->toBeNull()
        ->and($result->tokenPreview)->not->toBe(NOOP_DEVICE_TOKEN);
});

test('never logs the full device token', function () {
    Log::spy();

    (new NoopPushProvider)->send(noopToken(), noopPayload());

    Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context = []) {
        $blob = $message.json_encode($context);

        return ($context['token_preview'] ?? null) !== null
            && ! str_contains($blob, NOOP_DEVICE_TOKEN);
    });
});
