<?php

declare(strict_types=1);

use App\Jobs\PushNotifications\PushNotificationJob;
use App\Models\User;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\Services\PushNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function pnsPayload(): NotificationPayload
{
    return new NotificationPayload(
        title: 'Test notification',
        body: 'This is a test.',
        data: ['type' => 'account_security_notice'],
    );
}

function pnsService(): PushNotificationService
{
    return app(PushNotificationService::class);
}

// ─────────────────────────────────────────────────────────────────────────────
// Job dispatch
// ─────────────────────────────────────────────────────────────────────────────

test('sendToUser dispatches PushNotificationJob on the push queue', function () {
    Bus::fake();

    $user = User::factory()->create();

    pnsService()->sendToUser($user, pnsPayload());

    Bus::assertDispatched(PushNotificationJob::class, function (PushNotificationJob $job) use ($user) {
        return $job->userId === $user->id
            && $job->payload->title === 'Test notification';
    });
});

test('sendToUser dispatches exactly one job per call', function () {
    Bus::fake();

    $user = User::factory()->create();

    pnsService()->sendToUser($user, pnsPayload());

    Bus::assertDispatchedTimes(PushNotificationJob::class, 1);
});

test('sendToUser dispatches even when user has no active tokens', function () {
    Bus::fake();

    $user = User::factory()->create();

    pnsService()->sendToUser($user, pnsPayload());

    Bus::assertDispatched(PushNotificationJob::class);
});

test('sendToUser does not call PushProvider inline', function () {
    Bus::fake();

    $user = User::factory()->create();

    // If Http is called it means provider was invoked inline — fake it so the
    // test fails loudly if any HTTP request is made.
    Http::preventStrayRequests();

    pnsService()->sendToUser($user, pnsPayload());

    // Reaching here without Http exception means no inline provider call.
    Bus::assertDispatched(PushNotificationJob::class);
});

test('sendToUser logs user_id and payload title safely', function () {
    Bus::fake();
    Log::spy();

    $user = User::factory()->create();

    pnsService()->sendToUser($user, pnsPayload());

    Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context = []) use ($user) {
        return ($context['user_id'] ?? null) === $user->id
            && array_key_exists('title', $context);
    });
});
