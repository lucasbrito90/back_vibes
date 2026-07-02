<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\ProviderConnection;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Vibe;
use App\Models\VibeDeviceAction;
use App\SmartHome\Validation\ScheduleAutomationValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function automationValidator(): ScheduleAutomationValidator
{
    return new ScheduleAutomationValidator;
}

/**
 * Build a schedule owned by $user with a vibe and one device action wired to
 * the same user's provider connection.
 */
function validAutomationSchedule(User $user): Schedule
{
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);
    $device = Device::factory()->create([
        'user_id' => $user->id,
        'provider_connection_id' => $connection->id,
        'provider' => $connection->provider,
    ]);

    VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
        'sort_order' => 0,
    ]);

    return Schedule::factory()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
    ]);
}

test('returns false when schedule has no vibe', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $schedule = Schedule::factory()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
    ]);

    $schedule->setRelation('vibe', null);

    expect(automationValidator()->validate($schedule))->toBeFalse();
});

test('returns false when vibe belongs to another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $vibe = Vibe::factory()->create(['user_id' => $other->id]);

    $schedule = Schedule::factory()->create([
        'user_id' => $owner->id,
        'vibe_id' => $vibe->id,
    ]);

    expect(automationValidator()->validate($schedule))->toBeFalse();
});

test('returns false when a device action has no device', function () {
    $user = User::factory()->create();
    $schedule = validAutomationSchedule($user);

    $schedule->load('vibe.deviceActions.device.providerConnection');
    $schedule->vibe->deviceActions->first()->setRelation('device', null);

    expect(automationValidator()->validate($schedule))->toBeFalse();
});

test('returns false when device belongs to another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $vibe = Vibe::factory()->create(['user_id' => $owner->id]);
    $connection = ProviderConnection::factory()->create(['user_id' => $owner->id]);
    $foreignDevice = Device::factory()->create([
        'user_id' => $other->id,
        'provider_connection_id' => ProviderConnection::factory()->create(['user_id' => $other->id])->id,
        'provider' => $connection->provider,
    ]);

    VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $foreignDevice->id,
    ]);

    $schedule = Schedule::factory()->create([
        'user_id' => $owner->id,
        'vibe_id' => $vibe->id,
    ]);

    expect(automationValidator()->validate($schedule))->toBeFalse();
});

test('returns false when device has no provider connection', function () {
    $user = User::factory()->create();
    $schedule = validAutomationSchedule($user);

    $schedule->load('vibe.deviceActions.device.providerConnection');
    $schedule->vibe->deviceActions->first()->device->setRelation('providerConnection', null);

    expect(automationValidator()->validate($schedule))->toBeFalse();
});

test('returns false when provider connection belongs to another user', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $vibe = Vibe::factory()->create(['user_id' => $owner->id]);
    $foreignConnection = ProviderConnection::factory()->create(['user_id' => $other->id]);
    $device = Device::factory()->create([
        'user_id' => $owner->id,
        'provider_connection_id' => $foreignConnection->id,
        'provider' => $foreignConnection->provider,
    ]);

    VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
    ]);

    $schedule = Schedule::factory()->create([
        'user_id' => $owner->id,
        'vibe_id' => $vibe->id,
    ]);

    expect(automationValidator()->validate($schedule))->toBeFalse();
});

test('returns true when vibe has multiple valid device actions', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);
    $connection = ProviderConnection::factory()->create(['user_id' => $user->id]);

    foreach ([0, 1] as $sortOrder) {
        $device = Device::factory()->create([
            'user_id' => $user->id,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'provider_device_id' => "light.room_{$sortOrder}",
        ]);

        VibeDeviceAction::factory()->create([
            'vibe_id' => $vibe->id,
            'device_id' => $device->id,
            'sort_order' => $sortOrder,
        ]);
    }

    $schedule = Schedule::factory()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
    ]);

    expect(automationValidator()->validate($schedule))->toBeTrue();
});

test('returns true for a fully valid automation schedule', function () {
    $user = User::factory()->create();
    $schedule = validAutomationSchedule($user);

    expect(automationValidator()->validate($schedule))->toBeTrue();
});

test('returns true when vibe has no device actions', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->create(['user_id' => $user->id]);

    $schedule = Schedule::factory()->create([
        'user_id' => $user->id,
        'vibe_id' => $vibe->id,
    ]);

    expect(automationValidator()->validate($schedule))->toBeTrue();
});
