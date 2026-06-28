<?php

declare(strict_types=1);

use App\Jobs\PushNotifications\PushNotificationJob;
use App\Models\PushToken;
use App\Models\User;
use App\PushNotifications\Contracts\PushProvider;
use App\PushNotifications\Contracts\PushProviderResolver;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\DTOs\PushResult;
use App\PushNotifications\Providers\NoopPushProvider;
use App\PushNotifications\Services\PushTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

const JOB_DEVICE_TOKEN = 'fcm:job-test-device-token-ABCDEF0123456789-this-is-long-enough';

function pnjPayload(): NotificationPayload
{
    return new NotificationPayload(
        title: 'Test push',
        body: 'Job test.',
        data: ['type' => 'account_security_notice'],
    );
}

function pnjJob(int $userId): PushNotificationJob
{
    return new PushNotificationJob($userId, pnjPayload());
}

/**
 * Build a mock PushProvider that returns a given PushResult.
 */
function mockProvider(PushResult $result): PushProvider
{
    $mock = Mockery::mock(PushProvider::class);
    $mock->shouldReceive('send')->andReturn($result);

    return $mock;
}

/**
 * Build a PushProviderResolver stub that always returns the given provider.
 */
function stubResolver(PushProvider $provider): PushProviderResolver
{
    $resolver = Mockery::mock(PushProviderResolver::class);
    $resolver->shouldReceive('resolve')->andReturn($provider);

    return $resolver;
}

function successResult(string $preview = 'fcm:jo...9'): PushResult
{
    return PushResult::success(provider: 'fcm', statusCode: 200, messageId: 'projects/test/messages/1', tokenPreview: $preview);
}

function failureResult(string $errorCode, string $preview = 'fcm:jo...9'): PushResult
{
    return PushResult::failure(provider: 'fcm', statusCode: 404, errorCode: $errorCode, errorMessage: 'Error.', tokenPreview: $preview);
}

// ─────────────────────────────────────────────────────────────────────────────
// Fan-out to tokens
// ─────────────────────────────────────────────────────────────────────────────

test('job sends to all active tokens for the user', function () {
    $user = User::factory()->create();
    PushToken::factory()->count(3)->for($user)->create();

    $provider = mockProvider(successResult());
    $resolver = Mockery::mock(PushProviderResolver::class);
    $resolver->shouldReceive('resolve')->times(3)->andReturn($provider);

    app(PushNotificationJob::class, ['userId' => $user->id, 'payload' => pnjPayload()])
        ->handle($resolver, app(PushTokenService::class));
});

test('job skips inactive tokens', function () {
    $user = User::factory()->create();
    PushToken::factory()->for($user)->inactive()->create();

    $provider = mockProvider(successResult());
    $resolver = Mockery::mock(PushProviderResolver::class);
    $resolver->shouldReceive('resolve')->never();

    pnjJob($user->id)->handle($resolver, app(PushTokenService::class));
});

// ─────────────────────────────────────────────────────────────────────────────
// Missing user / no tokens
// ─────────────────────────────────────────────────────────────────────────────

test('logs a warning and returns when user does not exist', function () {
    Log::spy();

    $resolver = Mockery::mock(PushProviderResolver::class);
    $resolver->shouldReceive('resolve')->never();

    pnjJob(99999)->handle($resolver, app(PushTokenService::class));

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn ($msg) => str_contains($msg, 'user not found')
    );
});

test('logs info and returns when user has no active tokens', function () {
    Log::spy();

    $user = User::factory()->create();

    $resolver = Mockery::mock(PushProviderResolver::class);
    $resolver->shouldReceive('resolve')->never();

    pnjJob($user->id)->handle($resolver, app(PushTokenService::class));

    Log::shouldHaveReceived('info')->withArgs(
        fn ($msg) => str_contains($msg, 'no active push tokens')
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// Per-token failure isolation
// ─────────────────────────────────────────────────────────────────────────────

test('one token failure does not stop delivery to other tokens', function () {
    $user = User::factory()->create();
    PushToken::factory()->count(2)->for($user)->create();

    $callCount = 0;
    $provider = Mockery::mock(PushProvider::class);
    $provider->shouldReceive('send')->andReturnUsing(function () use (&$callCount) {
        $callCount++;
        if ($callCount === 1) {
            throw new RuntimeException('Simulated provider crash');
        }

        return successResult();
    });

    $resolver = stubResolver($provider);

    pnjJob($user->id)->handle($resolver, app(PushTokenService::class));

    expect($callCount)->toBe(2);
});

test('per-token exception is logged safely without full token', function () {
    Log::spy();

    $user = User::factory()->create();
    PushToken::factory()->for($user)->create(['token' => JOB_DEVICE_TOKEN]);

    $provider = Mockery::mock(PushProvider::class);
    $provider->shouldReceive('send')->andThrow(new RuntimeException('crash'));

    pnjJob($user->id)->handle(stubResolver($provider), app(PushTokenService::class));

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context = []) {
        return str_contains($message, 'unexpected error')
            && ! str_contains(json_encode($context), JOB_DEVICE_TOKEN);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Logging — success and failure
// ─────────────────────────────────────────────────────────────────────────────

test('logs info safely on successful delivery', function () {
    Log::spy();

    $user = User::factory()->create();
    PushToken::factory()->for($user)->create();

    pnjJob($user->id)->handle(stubResolver(mockProvider(successResult())), app(PushTokenService::class));

    Log::shouldHaveReceived('info')->withArgs(fn ($msg) => str_contains($msg, 'push delivered'));
});

test('logs warning safely on failed delivery', function () {
    Log::spy();

    $user = User::factory()->create();
    PushToken::factory()->for($user)->create();

    pnjJob($user->id)->handle(stubResolver(mockProvider(failureResult('SOME_ERROR'))), app(PushTokenService::class));

    Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, 'push failed'));
});

test('no full token appears in any log context', function () {
    Log::spy();

    $user = User::factory()->create();
    PushToken::factory()->for($user)->create(['token' => JOB_DEVICE_TOKEN]);

    pnjJob($user->id)->handle(stubResolver(mockProvider(successResult())), app(PushTokenService::class));

    Log::shouldNotHaveReceived('info', [Mockery::on(function ($args) {
        return str_contains(json_encode($args), JOB_DEVICE_TOKEN);
    })]);
});

// ─────────────────────────────────────────────────────────────────────────────
// Invalid token deactivation
// ─────────────────────────────────────────────────────────────────────────────

test('UNREGISTERED error deactivates the token', function () {
    $user = User::factory()->create();
    $token = PushToken::factory()->for($user)->create();

    expect($token->is_active)->toBeTrue();

    pnjJob($user->id)->handle(
        stubResolver(mockProvider(failureResult('UNREGISTERED', $token->tokenPreview()))),
        app(PushTokenService::class),
    );

    expect($token->fresh()->is_active)->toBeFalse()
        ->and($token->fresh()->revoked_at)->not->toBeNull();
});

test('NOT_FOUND error deactivates the token', function () {
    $user = User::factory()->create();
    $token = PushToken::factory()->for($user)->create();

    expect($token->is_active)->toBeTrue();

    pnjJob($user->id)->handle(
        stubResolver(mockProvider(failureResult('NOT_FOUND', $token->tokenPreview()))),
        app(PushTokenService::class),
    );

    expect($token->fresh()->is_active)->toBeFalse();
});

test('INVALID_ARGUMENT error does not deactivate the token', function () {
    $user = User::factory()->create();
    $token = PushToken::factory()->for($user)->create();

    pnjJob($user->id)->handle(
        stubResolver(mockProvider(failureResult('INVALID_ARGUMENT', $token->tokenPreview()))),
        app(PushTokenService::class),
    );

    expect($token->fresh()->is_active)->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// Queue configuration
// ─────────────────────────────────────────────────────────────────────────────

test('job has correct queue name from config', function () {
    $job = pnjJob(1);

    expect($job->queue)->toBe(config('push_notifications.queue.name', 'push'));
});

test('job has correct timeout from config', function () {
    $job = pnjJob(1);

    expect($job->timeout)->toBe((int) config('push_notifications.queue.timeout', 30));
});

test('job has correct tries from config', function () {
    $job = pnjJob(1);

    expect($job->tries)->toBe((int) config('push_notifications.queue.tries', 3));
});

// ─────────────────────────────────────────────────────────────────────────────
// Noop provider path
// ─────────────────────────────────────────────────────────────────────────────

test('Noop provider path works without HTTP calls', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create();
    PushToken::factory()->for($user)->create();

    $noop = app(NoopPushProvider::class);
    $resolver = stubResolver($noop);

    pnjJob($user->id)->handle($resolver, app(PushTokenService::class));

    // No exception = no stray HTTP, noop path completed successfully.
    expect(true)->toBeTrue();
});
