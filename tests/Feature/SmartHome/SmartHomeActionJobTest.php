<?php

declare(strict_types=1);

use App\Jobs\SmartHome\SmartHomeActionJob;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\VibeDeviceAction;
use App\SmartHome\ProviderAdapterResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

const JOB_HA_BASE = 'https://ha.example.test';

/**
 * Build a fully-wired action: HA provider connection → device → vibe action.
 *
 * @param  array<string, mixed>  $connOverrides
 * @param  array<string, mixed>  $deviceOverrides
 * @param  array<string, mixed>  $actionOverrides
 */
function jobAction(array $connOverrides = [], array $deviceOverrides = [], array $actionOverrides = []): VibeDeviceAction
{
    $connection = ProviderConnection::factory()->create(array_merge([
        'config' => ['base_url' => JOB_HA_BASE],
    ], $connOverrides));

    $device = Device::factory()->create(array_merge([
        'provider_connection_id' => $connection->id,
        'user_id' => $connection->user_id,
        'provider' => $connection->provider,
        'provider_device_id' => 'light.living_room',
    ], $deviceOverrides));

    return VibeDeviceAction::factory()->create(array_merge([
        'device_id' => $device->id,
        'action_type' => 'turn_on',
        'parameters' => null,
    ], $actionOverrides));
}

function runJob(VibeDeviceAction|int $action): void
{
    $id = $action instanceof VibeDeviceAction ? $action->id : $action;
    (new SmartHomeActionJob($id))->handle(app(ProviderAdapterResolver::class));
}

// ─────────────────────────────────────────────────────────────────────────────
// Queue configuration
// ─────────────────────────────────────────────────────────────────────────────

it('is configured to run on the smart-home queue', function () {
    $job = new SmartHomeActionJob(1);

    expect($job->queue)->toBe('smart-home');
});

it('has the expected timeout and tries', function () {
    $job = new SmartHomeActionJob(1);

    expect($job->timeout)->toBe(30)
        ->and($job->tries)->toBe(3);
});

// ─────────────────────────────────────────────────────────────────────────────
// Real execution via adapter (Http::fake — no real Home Assistant)
// ─────────────────────────────────────────────────────────────────────────────

it('executes the adapter for an existing action', function () {
    Http::fake([JOB_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    $action = jobAction();

    runJob($action);

    Http::assertSentCount(1);
});

it('passes the correct provider_device_id, action_type and parameters to the provider', function () {
    Http::fake([JOB_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    $action = jobAction(actionOverrides: [
        'action_type' => 'turn_off',
        'parameters' => ['transition_marker' => 'x'],
    ]);

    runJob($action);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/api/services/light/turn_off')
            && $request['entity_id'] === 'light.living_room'
            && $request['transition_marker'] === 'x';
    });
});

it('passes only entity_id when parameters are null', function () {
    Http::fake([JOB_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    $action = jobAction(actionOverrides: ['action_type' => 'toggle', 'parameters' => null]);

    runJob($action);

    Http::assertSent(function (Request $request) {
        return str_contains($request->url(), '/api/services/light/toggle')
            && $request['entity_id'] === 'light.living_room'
            && array_keys($request->data()) === ['entity_id'];
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Success / failure logging
// ─────────────────────────────────────────────────────────────────────────────

it('logs success when the provider returns 2xx', function () {
    Http::fake([JOB_HA_BASE.'/api/services/*' => Http::response([], 200)]);
    Log::spy();

    runJob(jobAction());

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'executed successfully')
            && $context['success'] === true
            && $context['status_code'] === 200);
});

it('logs a warning on a failed ActionResult but does not throw', function () {
    Http::fake([JOB_HA_BASE.'/api/services/*' => Http::response([], 500)]);
    Log::spy();

    $action = jobAction();

    expect(fn () => runJob($action))->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'execution failed')
            && $context['success'] === false
            && $context['status_code'] === 500);
});

it('handles a provider connection failure as a completed failed result (no throw)', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));
    Log::spy();

    $action = jobAction();

    expect(fn () => runJob($action))->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'execution failed')
            && $context['success'] === false);
});

// ─────────────────────────────────────────────────────────────────────────────
// Graceful handling — unsupported action, missing relations, exceptions
// ─────────────────────────────────────────────────────────────────────────────

it('handles an unsupported action gracefully without HTTP or throw', function () {
    Http::fake();
    Log::spy();

    $action = jobAction(actionOverrides: ['action_type' => 'explode']);

    expect(fn () => runJob($action))->not->toThrow(Throwable::class);

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message) => str_contains($message, 'unsupported action'));
});

it('handles a missing/deleted action gracefully', function () {
    Http::fake();
    Log::spy();

    expect(fn () => runJob(999_999))->not->toThrow(Throwable::class);

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'not found or deleted'));
});

it('handles a deleted device gracefully (cascade removes the action)', function () {
    Http::fake();
    Log::spy();

    $action = jobAction();
    $actionId = $action->id;

    // device_id has cascadeOnDelete: deleting the device removes its actions,
    // so in production the job hits the "action not found" graceful branch.
    $action->device->delete();

    expect(fn () => runJob($actionId))->not->toThrow(Throwable::class);

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'not found or deleted'));
});

it('handles an unexpected resolver error gracefully (unknown provider)', function () {
    Http::fake();
    Log::spy();

    // Force an unknown provider so the resolver throws InvalidArgumentException.
    $action = jobAction(connOverrides: ['provider' => 'unknown_provider'], deviceOverrides: ['provider' => 'unknown_provider']);

    expect(fn () => runJob($action))->not->toThrow(Throwable::class);

    Http::assertNothingSent();
    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'unexpected error')
            && $context['success'] === false);
});

// ─────────────────────────────────────────────────────────────────────────────
// Security — never log credentials
// ─────────────────────────────────────────────────────────────────────────────

it('never logs the access token or credentials', function () {
    Http::fake([JOB_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    $connection = ProviderConnection::factory()->create(['config' => ['base_url' => JOB_HA_BASE]]);
    $connection->setEncryptedCredentials(['access_token' => 'super-secret-token-value']);
    $connection->save();

    $device = Device::factory()->create([
        'provider_connection_id' => $connection->id,
        'user_id' => $connection->user_id,
        'provider' => $connection->provider,
        'provider_device_id' => 'light.living_room',
    ]);
    $action = VibeDeviceAction::factory()->create(['device_id' => $device->id, 'action_type' => 'turn_on']);

    Log::spy();

    runJob($action);

    $forbidden = ['super-secret-token-value', 'access_token', 'encrypted_credentials'];

    $assertClean = function ($message, $context) use ($forbidden) {
        $serialised = $message.' '.json_encode($context);
        foreach ($forbidden as $needle) {
            if (str_contains($serialised, $needle)) {
                return false;
            }
        }

        return true;
    };

    Log::shouldHaveReceived('info')->withArgs($assertClean);
});

// ─────────────────────────────────────────────────────────────────────────────
// Job uses adapter/resolver, not arbitrary direct HTTP
// ─────────────────────────────────────────────────────────────────────────────

it('only performs the single provider call routed through the adapter', function () {
    Http::fake([JOB_HA_BASE.'/api/services/*' => Http::response([], 200)]);

    runJob(jobAction());

    // Exactly one request, to the HA services endpoint — proves the job goes
    // through the adapter rather than making ad-hoc HTTP calls.
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/services/'));
});
