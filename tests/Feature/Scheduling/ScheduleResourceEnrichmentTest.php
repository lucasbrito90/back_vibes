<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Schedule;
use App\Models\User;
use App\Models\Vibe;
use App\Models\VibeDeviceAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function schedEnrichJwt(User $user): UnencryptedToken
{
    $dataset = new DataSet([
        'sub' => $user->firebase_uid,
        'email' => $user->email,
        'name' => $user->name,
    ], 'e30.');

    $jwt = Mockery::mock(UnencryptedToken::class);
    $jwt->shouldReceive('claims')->andReturn($dataset);

    return $jwt;
}

function schedEnrichAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(schedEnrichJwt($user)));
}

function schedEnrichHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

/**
 * Creates a user, their vibe, and a schedule pointing to that vibe.
 * Returns [$user, $vibe, $schedule].
 */
function schedEnrichSetup(string $uid, string $vibeName = 'Test Vibe'): array
{
    $user = User::factory()->create(['firebase_uid' => $uid]);
    $vibe = Vibe::factory()->for($user)->create(['name' => $vibeName]);
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    return [$user, $vibe, $schedule];
}

/**
 * Attaches a VibeDeviceAction to the given vibe.
 * The device is created independently (owned by its own user) since this is read-model only.
 */
function addDeviceAction(Vibe $vibe): VibeDeviceAction
{
    $device = Device::factory()->create();

    return VibeDeviceAction::factory()->create([
        'vibe_id' => $vibe->id,
        'device_id' => $device->id,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// vibe_name field
// ─────────────────────────────────────────────────────────────────────────────

test('ScheduleResource includes vibe_name on show', function () {
    [$user, $vibe, $schedule] = schedEnrichSetup('fb-se-vibename-show', 'Evening Calm');
    schedEnrichAuth($user);

    $this->getJson("/api/schedules/{$schedule->id}", schedEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.vibe_name', 'Evening Calm');
});

test('ScheduleResource includes vibe_name on index', function () {
    [$user, $vibe, $schedule] = schedEnrichSetup('fb-se-vibename-index', 'Morning Focus');
    schedEnrichAuth($user);

    $response = $this->getJson('/api/schedules', schedEnrichHeaders())->assertOk();

    expect($response->json('data.0.vibe_name'))->toBe('Morning Focus');
});

test('ScheduleResource includes vibe_name on store', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-se-vibename-store']);
    $vibe = Vibe::factory()->for($user)->create(['name' => 'Stored Vibe']);
    schedEnrichAuth($user);

    $this->postJson('/api/schedules', [
        'vibe_id' => $vibe->id,
        'name' => 'New Schedule',
        'timezone' => 'UTC',
        'start_time' => now('UTC')->addHour()->toDateTimeString(),
        'recurrence_type' => 'daily',
        'is_enabled' => true,
    ], schedEnrichHeaders())
        ->assertCreated()
        ->assertJsonPath('data.vibe_name', 'Stored Vibe');
});

test('ScheduleResource includes vibe_name on update', function () {
    [$user, $vibe, $schedule] = schedEnrichSetup('fb-se-vibename-update', 'Updated Vibe');
    schedEnrichAuth($user);

    $this->patchJson("/api/schedules/{$schedule->id}", ['name' => 'Renamed'], schedEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.vibe_name', 'Updated Vibe');
});

// ─────────────────────────────────────────────────────────────────────────────
// device_actions_count — 0 / 1 / multiple
// ─────────────────────────────────────────────────────────────────────────────

test('device_actions_count is 0 when vibe has no device actions', function () {
    [$user, $vibe, $schedule] = schedEnrichSetup('fb-se-dac-zero');
    schedEnrichAuth($user);

    $this->getJson("/api/schedules/{$schedule->id}", schedEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.device_actions_count', 0);
});

test('device_actions_count is 1 when vibe has one device action', function () {
    [$user, $vibe, $schedule] = schedEnrichSetup('fb-se-dac-one');
    addDeviceAction($vibe);
    schedEnrichAuth($user);

    $this->getJson("/api/schedules/{$schedule->id}", schedEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.device_actions_count', 1);
});

test('device_actions_count is correct when vibe has multiple device actions', function () {
    [$user, $vibe, $schedule] = schedEnrichSetup('fb-se-dac-multi');
    addDeviceAction($vibe);
    addDeviceAction($vibe);
    addDeviceAction($vibe);
    schedEnrichAuth($user);

    $this->getJson("/api/schedules/{$schedule->id}", schedEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.device_actions_count', 3);
});

test('device_actions_count is returned correctly on index', function () {
    [$user, $vibe, $schedule] = schedEnrichSetup('fb-se-dac-index');
    addDeviceAction($vibe);
    addDeviceAction($vibe);
    schedEnrichAuth($user);

    $response = $this->getJson('/api/schedules', schedEnrichHeaders())->assertOk();

    expect($response->json('data.0.device_actions_count'))->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// has_device_actions — true / false
// ─────────────────────────────────────────────────────────────────────────────

test('has_device_actions is false when vibe has no device actions', function () {
    [$user, $vibe, $schedule] = schedEnrichSetup('fb-se-hda-false');
    schedEnrichAuth($user);

    $this->getJson("/api/schedules/{$schedule->id}", schedEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.has_device_actions', false);
});

test('has_device_actions is true when vibe has at least one device action', function () {
    [$user, $vibe, $schedule] = schedEnrichSetup('fb-se-hda-true');
    addDeviceAction($vibe);
    schedEnrichAuth($user);

    $this->getJson("/api/schedules/{$schedule->id}", schedEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.has_device_actions', true);
});

test('has_device_actions is true when vibe has multiple device actions', function () {
    [$user, $vibe, $schedule] = schedEnrichSetup('fb-se-hda-multi');
    addDeviceAction($vibe);
    addDeviceAction($vibe);
    schedEnrichAuth($user);

    $this->getJson("/api/schedules/{$schedule->id}", schedEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.has_device_actions', true);
});

// ─────────────────────────────────────────────────────────────────────────────
// device_actions_count counts only actions of the schedule's own vibe
// ─────────────────────────────────────────────────────────────────────────────

test('device_actions_count reflects only the actions on the linked vibe', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-se-dac-isolation']);

    $vibeA = Vibe::factory()->for($user)->create(['name' => 'Vibe A']);
    $vibeB = Vibe::factory()->for($user)->create(['name' => 'Vibe B']);

    $scheduleA = Schedule::factory()->daily()->for($user, 'user')->for($vibeA, 'vibe')->create();
    Schedule::factory()->daily()->for($user, 'user')->for($vibeB, 'vibe')->create();

    // Attach 2 actions to Vibe A and 5 to Vibe B.
    addDeviceAction($vibeA);
    addDeviceAction($vibeA);
    addDeviceAction($vibeB);
    addDeviceAction($vibeB);
    addDeviceAction($vibeB);
    addDeviceAction($vibeB);
    addDeviceAction($vibeB);

    schedEnrichAuth($user);

    $this->getJson("/api/schedules/{$scheduleA->id}", schedEnrichHeaders())
        ->assertOk()
        ->assertJsonPath('data.device_actions_count', 2)
        ->assertJsonPath('data.has_device_actions', true);
});

// ─────────────────────────────────────────────────────────────────────────────
// Full resource shape includes new fields
// ─────────────────────────────────────────────────────────────────────────────

test('ScheduleResource includes vibe_name, device_actions_count and has_device_actions in structure', function () {
    [$user, $vibe, $schedule] = schedEnrichSetup('fb-se-structure');
    schedEnrichAuth($user);

    $this->getJson("/api/schedules/{$schedule->id}", schedEnrichHeaders())
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'vibe_id',
                'name',
                'timezone',
                'start_time',
                'recurrence_type',
                'recurrence_config',
                'is_enabled',
                'next_run_at',
                'last_run_at',
                'created_at',
                'updated_at',
                'vibe_name',
                'device_actions_count',
                'has_device_actions',
            ],
        ]);
});
