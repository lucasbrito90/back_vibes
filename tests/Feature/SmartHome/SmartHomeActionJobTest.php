<?php

declare(strict_types=1);

use App\Jobs\SmartHome\SmartHomeActionJob;
use App\Models\VibeDeviceAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Job contract
// ─────────────────────────────────────────────────────────────────────────────

it('handles an existing action without exception', function () {
    $action = VibeDeviceAction::factory()->create();

    expect(fn () => (new SmartHomeActionJob($action->id))->handle())->not->toThrow(Throwable::class);
});

it('handles a deleted/missing action gracefully without exception', function () {
    expect(fn () => (new SmartHomeActionJob(999_999))->handle())->not->toThrow(Throwable::class);
});

it('is configured to run on the smart-home queue', function () {
    $action = VibeDeviceAction::factory()->create();
    $job = new SmartHomeActionJob($action->id);

    $job->handle();

    // Verify queue name is set on the job instance via onQueue in constructor.
    expect($job->queue)->toBe('smart-home');
});

it('has the expected timeout and tries', function () {
    $job = new SmartHomeActionJob(1);

    expect($job->timeout)->toBe(30)
        ->and($job->tries)->toBe(3);
});

// ─────────────────────────────────────────────────────────────────────────────
// Phase 8 stub — no provider calls
// ─────────────────────────────────────────────────────────────────────────────

it('does not call HomeAssistantAdapter (verified via no HTTP)', function () {
    Http::fake();

    $action = VibeDeviceAction::factory()->create();

    (new SmartHomeActionJob($action->id))->handle();

    // If the adapter were called it would attempt HTTP to HA — none expected.
    Http::assertNothingSent();
});

it('does not call ProviderAdapterResolver (verified via no HTTP)', function () {
    Http::fake();

    $action = VibeDeviceAction::factory()->create();

    (new SmartHomeActionJob($action->id))->handle();

    Http::assertNothingSent();
});

it('does not make any HTTP request', function () {
    Http::fake();

    $action = VibeDeviceAction::factory()->create();

    (new SmartHomeActionJob($action->id))->handle();

    Http::assertNothingSent();
});

it('logs intent when action exists (no exception)', function () {
    Log::spy();

    $action = VibeDeviceAction::factory()->create();

    expect(fn () => (new SmartHomeActionJob($action->id))->handle())->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('info')->once();
});

it('logs a warning when action is missing (no exception)', function () {
    Log::spy();

    expect(fn () => (new SmartHomeActionJob(999_999))->handle())->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('warning')->once();
});
