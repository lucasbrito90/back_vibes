<?php

declare(strict_types=1);

use App\Models\PushToken;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\DTOs\PushResult;
use App\PushNotifications\Exceptions\FcmAuthenticationException;
use App\PushNotifications\Exceptions\FcmConfigurationException;
use App\PushNotifications\Providers\FcmPushProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Constants + helpers
// ─────────────────────────────────────────────────────────────────────────────

const FCM_TOKEN_URI = 'https://oauth2.googleapis.com/token';
const FCM_PROJECT_ID = 'demo-project';
const FCM_SEND_URL = 'https://fcm.googleapis.com/v1/projects/demo-project/messages:send';
const FCM_CACHE_KEY = 'test:fcm:oauth';
const FCM_DEVICE_TOKEN = 'fcm-device-token-ABCDEF0123456789-this-is-long-enough-to-preview';

/**
 * A reusable generated RSA service account so JWT signing actually runs once.
 *
 * @return array<string, mixed>
 */
function fcmServiceAccount(): array
{
    static $account = null;

    if ($account !== null) {
        return $account;
    }

    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($resource, $privateKey);

    return $account = [
        'type' => 'service_account',
        'project_id' => FCM_PROJECT_ID,
        'private_key' => $privateKey,
        'client_email' => 'fcm@demo-project.iam.gserviceaccount.com',
        'token_uri' => FCM_TOKEN_URI,
    ];
}

/**
 * @param  array<string, mixed>|string|null  $credentials
 */
function fcmProvider(array|string|null $credentials = 'default'): FcmPushProvider
{
    return new FcmPushProvider(
        credentials: $credentials === 'default' ? fcmServiceAccount() : $credentials,
        projectId: FCM_PROJECT_ID,
        scope: 'https://www.googleapis.com/auth/firebase.messaging',
        httpTimeout: 5,
        tokenCacheKey: FCM_CACHE_KEY,
        tokenExpirySkew: 60,
    );
}

function fcmToken(string $token = FCM_DEVICE_TOKEN): PushToken
{
    $model = new PushToken;
    $model->token = $token;
    $model->platform = 'android';
    $model->provider = 'fcm';

    return $model;
}

function fcmPayload(): NotificationPayload
{
    return new NotificationPayload(
        title: 'Schedule failed',
        body: 'Your vibe did not start.',
        data: ['type' => 'schedule_execution_failed', 'schedule_id' => '123'],
    );
}

/**
 * Fake both the OAuth token endpoint and the FCM send endpoint.
 *
 * @param  callable(Request): mixed  $sendResponder
 */
function fakeFcm(callable $sendResponder, ?int &$tokenCalls = null, ?int &$sendCalls = null): void
{
    $tokenCalls ??= 0;
    $sendCalls ??= 0;

    Http::fake(function (Request $request) use ($sendResponder, &$tokenCalls, &$sendCalls) {
        if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
            $tokenCalls++;

            return Http::response(['access_token' => 'ya29.test-access-token', 'expires_in' => 3600], 200);
        }

        if (str_contains($request->url(), 'fcm.googleapis.com')) {
            $sendCalls++;

            return $sendResponder($request);
        }

        return Http::response([], 404);
    });
}

beforeEach(function () {
    Cache::flush();
});

// ─────────────────────────────────────────────────────────────────────────────
// OAuth token lifecycle
// ─────────────────────────────────────────────────────────────────────────────

test('generates an OAuth access token before sending', function () {
    $tokenCalls = 0;
    $sendCalls = 0;
    fakeFcm(
        fn () => Http::response(['name' => 'projects/demo-project/messages/1'], 200),
        $tokenCalls,
        $sendCalls,
    );

    $result = fcmProvider()->send(fcmToken(), fcmPayload());

    expect($result->success)->toBeTrue()
        ->and($tokenCalls)->toBe(1)
        ->and($sendCalls)->toBe(1);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token')
        && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
        && is_string($r['assertion']) && str_contains($r['assertion'], '.'));
});

test('caches the OAuth token across sends', function () {
    $tokenCalls = 0;
    $sendCalls = 0;
    fakeFcm(
        fn () => Http::response(['name' => 'projects/demo-project/messages/1'], 200),
        $tokenCalls,
        $sendCalls,
    );

    $provider = fcmProvider();
    $provider->send(fcmToken(), fcmPayload());
    $provider->send(fcmToken(), fcmPayload());

    expect($tokenCalls)->toBe(1)
        ->and($sendCalls)->toBe(2);
});

test('refreshes the OAuth token after it expires', function () {
    $tokenCalls = 0;
    $sendCalls = 0;
    fakeFcm(
        fn () => Http::response(['name' => 'projects/demo-project/messages/1'], 200),
        $tokenCalls,
        $sendCalls,
    );

    $provider = fcmProvider();
    $provider->send(fcmToken(), fcmPayload());
    expect($tokenCalls)->toBe(1);

    // Move past the 3600s token lifetime so the cached token is considered expired.
    $this->travel(3700)->seconds();

    $provider->send(fcmToken(), fcmPayload());

    expect($tokenCalls)->toBe(2)
        ->and($sendCalls)->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// Request format
// ─────────────────────────────────────────────────────────────────────────────

test('sends a Bearer Authorization header on the FCM request', function () {
    fakeFcm(fn () => Http::response(['name' => 'projects/demo-project/messages/1'], 200));

    fcmProvider()->send(fcmToken(), fcmPayload());

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'messages:send')
        && $r->hasHeader('Authorization', 'Bearer ya29.test-access-token'));
});

test('formats the FCM HTTP v1 message body correctly', function () {
    fakeFcm(fn () => Http::response(['name' => 'projects/demo-project/messages/1'], 200));

    $payload = new NotificationPayload(
        title: 'Hello',
        body: 'World',
        data: ['type' => 'account_security_notice'],
        androidConfig: ['priority' => 'high'],
    );

    fcmProvider()->send(fcmToken(), $payload);

    Http::assertSent(function (Request $r) {
        if (! str_contains($r->url(), FCM_SEND_URL)) {
            return false;
        }

        $message = $r['message'] ?? [];

        return ($message['token'] ?? null) === FCM_DEVICE_TOKEN
            && ($message['notification']['title'] ?? null) === 'Hello'
            && ($message['notification']['body'] ?? null) === 'World'
            && ($message['data']['type'] ?? null) === 'account_security_notice'
            && ($message['android']['priority'] ?? null) === 'high';
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Send outcomes
// ─────────────────────────────────────────────────────────────────────────────

test('returns a successful PushResult with message id on 200', function () {
    fakeFcm(fn () => Http::response(['name' => 'projects/demo-project/messages/0:9999'], 200));

    $result = fcmProvider()->send(fcmToken(), fcmPayload());

    expect($result)->toBeInstanceOf(PushResult::class)
        ->and($result->success)->toBeTrue()
        ->and($result->statusCode)->toBe(200)
        ->and($result->messageId)->toBe('projects/demo-project/messages/0:9999')
        ->and($result->errorCode)->toBeNull();
});

test('maps an invalid token (400 INVALID_ARGUMENT) to a failed PushResult', function () {
    fakeFcm(fn () => Http::response([
        'error' => [
            'code' => 400,
            'message' => 'The registration token is not a valid FCM registration token',
            'status' => 'INVALID_ARGUMENT',
            'details' => [
                ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'INVALID_ARGUMENT'],
            ],
        ],
    ], 400));

    $result = fcmProvider()->send(fcmToken(), fcmPayload());

    expect($result->success)->toBeFalse()
        ->and($result->statusCode)->toBe(400)
        ->and($result->errorCode)->toBe('INVALID_ARGUMENT')
        ->and($result->errorMessage)->not->toBeNull();
});

test('maps an unregistered device (404 UNREGISTERED) to a failed PushResult', function () {
    fakeFcm(fn () => Http::response([
        'error' => [
            'code' => 404,
            'message' => 'Requested entity was not found.',
            'status' => 'NOT_FOUND',
            'details' => [
                ['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED'],
            ],
        ],
    ], 404));

    $result = fcmProvider()->send(fcmToken(), fcmPayload());

    expect($result->success)->toBeFalse()
        ->and($result->statusCode)->toBe(404)
        ->and($result->errorCode)->toBe('UNREGISTERED');
});

test('returns a network_error PushResult when the FCM send times out', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
            return Http::response(['access_token' => 'ya29.test-access-token', 'expires_in' => 3600], 200);
        }

        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $result = fcmProvider()->send(fcmToken(), fcmPayload());

    expect($result->success)->toBeFalse()
        ->and($result->statusCode)->toBeNull()
        ->and($result->errorCode)->toBe('network_error');
});

// ─────────────────────────────────────────────────────────────────────────────
// Credential / auth failures
// ─────────────────────────────────────────────────────────────────────────────

test('throws FcmAuthenticationException when the token endpoint rejects credentials', function () {
    Http::fake([
        FCM_TOKEN_URI => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT'], 400),
    ]);

    fcmProvider()->send(fcmToken(), fcmPayload());
})->throws(FcmAuthenticationException::class);

test('throws FcmAuthenticationException when the token endpoint is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

    fcmProvider()->send(fcmToken(), fcmPayload());
})->throws(FcmAuthenticationException::class);

test('throws FcmConfigurationException when credentials are missing', function () {
    fcmProvider(null)->send(fcmToken(), fcmPayload());
})->throws(FcmConfigurationException::class);

// ─────────────────────────────────────────────────────────────────────────────
// Safe logging — no device token leakage
// ─────────────────────────────────────────────────────────────────────────────

test('logs failures with a token preview and never the full device token', function () {
    Log::spy();

    fakeFcm(fn () => Http::response([
        'error' => ['code' => 404, 'message' => 'Requested entity was not found.', 'status' => 'NOT_FOUND'],
    ], 404));

    fcmProvider()->send(fcmToken(), fcmPayload());

    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context = []) {
        $blob = $message.json_encode($context);

        return ($context['token_preview'] ?? null) !== null
            && ! str_contains($blob, FCM_DEVICE_TOKEN);
    });
});

test('does not leak the device token in the request assertion or logs on success', function () {
    Log::spy();

    fakeFcm(fn () => Http::response(['name' => 'projects/demo-project/messages/1'], 200));

    fcmProvider()->send(fcmToken(), fcmPayload());

    // The OAuth assertion (JWT) must never embed the device token.
    Http::assertSent(function (Request $r) {
        if (! str_contains($r->url(), 'oauth2.googleapis.com/token')) {
            return true;
        }

        return ! str_contains((string) ($r['assertion'] ?? ''), FCM_DEVICE_TOKEN);
    });

    Log::shouldNotHaveReceived('warning');
});
