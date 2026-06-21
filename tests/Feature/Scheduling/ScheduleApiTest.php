<?php

declare(strict_types=1);

use App\Models\Schedule;
use App\Models\User;
use App\Models\Vibe;
use App\Services\Scheduling\RecurrenceType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ────────────────────────────────────────────────────────────────────────────
// Helpers
// ────────────────────────────────────────────────────────────────────────────

function scheduleJwt(User $user): UnencryptedToken
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

function scheduleAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(scheduleJwt($user)));
}

function scheduleHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function userWithVibe(?string $uid = null): array
{
    $user = User::factory()->create(['firebase_uid' => $uid ?? 'fb-sch-'.uniqid()]);
    $vibe = Vibe::factory()->for($user)->create();

    return [$user, $vibe];
}

function futureUtc(int $addHours = 2): string
{
    return CarbonImmutable::now('UTC')->addHours($addHours)->toDateTimeString();
}

function basePayload(int $vibeId): array
{
    return [
        'vibe_id' => $vibeId,
        'name' => 'Morning Focus',
        'timezone' => 'America/Sao_Paulo',
        'start_time' => futureUtc(),
        'recurrence_type' => RecurrenceType::Daily->value,
        'is_enabled' => true,
    ];
}

// ────────────────────────────────────────────────────────────────────────────
// Authentication
// ────────────────────────────────────────────────────────────────────────────

test('unauthenticated cannot list schedules', function () {
    $this->getJson('/api/schedules')->assertUnauthorized();
});

test('unauthenticated cannot create schedule', function () {
    $this->postJson('/api/schedules', ['name' => 'Nope'])->assertUnauthorized();
});

test('unauthenticated cannot show schedule', function () {
    $schedule = Schedule::factory()->create();
    $this->getJson("/api/schedules/{$schedule->id}")->assertUnauthorized();
});

test('unauthenticated cannot update schedule', function () {
    $schedule = Schedule::factory()->create();
    $this->patchJson("/api/schedules/{$schedule->id}", ['name' => 'x'])->assertUnauthorized();
});

test('unauthenticated cannot delete schedule', function () {
    $schedule = Schedule::factory()->create();
    $this->deleteJson("/api/schedules/{$schedule->id}")->assertUnauthorized();
});

// ────────────────────────────────────────────────────────────────────────────
// Index
// ────────────────────────────────────────────────────────────────────────────

test('authenticated user lists only own schedules', function () {
    [$alice, $aliceVibe] = userWithVibe('fb-sch-idx-alice');
    [$bob, $bobVibe] = userWithVibe('fb-sch-idx-bob');

    $mine = Schedule::factory()->daily()->for($alice, 'user')->for($aliceVibe, 'vibe')->create(['name' => 'Alice Morning']);
    Schedule::factory()->daily()->for($bob, 'user')->for($bobVibe, 'vibe')->create(['name' => 'Bob Evening']);

    scheduleAuth($alice);

    $response = $this->getJson('/api/schedules', scheduleHeaders())->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids)->toBe([$mine->id])
        ->and($response->json('data.0.name'))->toBe('Alice Morning');
});

test('index returns empty array when user has no schedules', function () {
    [$user] = userWithVibe('fb-sch-idx-empty');

    scheduleAuth($user);

    $this->getJson('/api/schedules', scheduleHeaders())
        ->assertOk()
        ->assertJsonPath('data', []);
});

// ────────────────────────────────────────────────────────────────────────────
// Show
// ────────────────────────────────────────────────────────────────────────────

test('user can show own schedule', function () {
    [$user, $vibe] = userWithVibe('fb-sch-show-own');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create(['name' => 'My Schedule']);

    scheduleAuth($user);

    $this->getJson("/api/schedules/{$schedule->id}", scheduleHeaders())
        ->assertOk()
        ->assertJsonPath('data.id', $schedule->id)
        ->assertJsonPath('data.name', 'My Schedule');
});

test('user cannot show another users schedule', function () {
    [$alice] = userWithVibe('fb-sch-show-alice');
    [$bob, $bobVibe] = userWithVibe('fb-sch-show-bob');

    $bobSchedule = Schedule::factory()->daily()->for($bob, 'user')->for($bobVibe, 'vibe')->create();

    scheduleAuth($alice);

    $this->getJson("/api/schedules/{$bobSchedule->id}", scheduleHeaders())->assertForbidden();
});

// ────────────────────────────────────────────────────────────────────────────
// Store — happy paths
// ────────────────────────────────────────────────────────────────────────────

test('user can create once schedule with owned vibe', function () {
    [$user, $vibe] = userWithVibe('fb-sch-store-once');

    scheduleAuth($user);

    $response = $this->postJson('/api/schedules', [
        'vibe_id' => $vibe->id,
        'name' => 'Single Fire',
        'timezone' => 'UTC',
        'start_time' => futureUtc(3),
        'recurrence_type' => RecurrenceType::Once->value,
        'is_enabled' => true,
    ], scheduleHeaders())->assertCreated();

    expect($response->json('data.recurrence_type'))->toBe('once')
        ->and($response->json('data.recurrence_config'))->toBeNull();
});

test('user can create daily schedule', function () {
    [$user, $vibe] = userWithVibe('fb-sch-store-daily');

    scheduleAuth($user);

    $this->postJson('/api/schedules', basePayload($vibe->id), scheduleHeaders())
        ->assertCreated()
        ->assertJsonPath('data.recurrence_type', 'daily');
});

test('user can create weekdays schedule', function () {
    [$user, $vibe] = userWithVibe('fb-sch-store-weekdays');

    scheduleAuth($user);

    $this->postJson('/api/schedules', [
        ...basePayload($vibe->id),
        'recurrence_type' => RecurrenceType::Weekdays->value,
    ], scheduleHeaders())
        ->assertCreated()
        ->assertJsonPath('data.recurrence_type', 'weekdays');
});

test('user can create weekly schedule with days_of_week', function () {
    [$user, $vibe] = userWithVibe('fb-sch-store-weekly');

    scheduleAuth($user);

    $this->postJson('/api/schedules', [
        ...basePayload($vibe->id),
        'recurrence_type' => RecurrenceType::Weekly->value,
        'recurrence_config' => ['days_of_week' => [1, 3, 5]],
    ], scheduleHeaders())
        ->assertCreated()
        ->assertJsonPath('data.recurrence_type', 'weekly')
        ->assertJsonPath('data.recurrence_config.days_of_week', [1, 3, 5]);
});

test('created schedule stores user_id from auth user', function () {
    [$user, $vibe] = userWithVibe('fb-sch-store-userid');

    scheduleAuth($user);

    $response = $this->postJson('/api/schedules', basePayload($vibe->id), scheduleHeaders())
        ->assertCreated();

    $schedule = Schedule::findOrFail($response->json('data.id'));
    expect($schedule->user_id)->toBe($user->id);
});

test('client-provided user_id is ignored and auth user id is used', function () {
    [$alice, $aliceVibe] = userWithVibe('fb-sch-store-uid-alice');
    [$bob] = userWithVibe('fb-sch-store-uid-bob');

    scheduleAuth($alice);

    $payload = [...basePayload($aliceVibe->id), 'user_id' => $bob->id];

    $response = $this->postJson('/api/schedules', $payload, scheduleHeaders())
        ->assertCreated();

    $schedule = Schedule::findOrFail($response->json('data.id'));
    expect($schedule->user_id)->toBe($alice->id)
        ->and(Schedule::where('user_id', $bob->id)->count())->toBe(0);
});

test('next_run_at is computed and present after store', function () {
    [$user, $vibe] = userWithVibe('fb-sch-store-nra');

    scheduleAuth($user);

    $response = $this->postJson('/api/schedules', basePayload($vibe->id), scheduleHeaders())
        ->assertCreated();

    expect($response->json('data.next_run_at'))->not->toBeNull();
});

test('next_run_at is null for disabled once schedule', function () {
    [$user, $vibe] = userWithVibe('fb-sch-store-disabled');

    scheduleAuth($user);

    $response = $this->postJson('/api/schedules', [
        ...basePayload($vibe->id),
        'recurrence_type' => RecurrenceType::Once->value,
        'is_enabled' => false,
    ], scheduleHeaders())->assertCreated();

    expect($response->json('data.next_run_at'))->toBeNull();
});

// ────────────────────────────────────────────────────────────────────────────
// Store — forbidden / ownership errors
// ────────────────────────────────────────────────────────────────────────────

test('cannot create schedule with another users vibe', function () {
    [$alice] = userWithVibe('fb-sch-store-xvibe-alice');
    [$bob, $bobVibe] = userWithVibe('fb-sch-store-xvibe-bob');

    scheduleAuth($alice);

    $this->postJson('/api/schedules', [
        ...basePayload($bobVibe->id),
    ], scheduleHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['vibe_id']);
});

// ────────────────────────────────────────────────────────────────────────────
// Store — validation errors
// ────────────────────────────────────────────────────────────────────────────

test('invalid timezone returns 422', function () {
    [$user, $vibe] = userWithVibe('fb-sch-val-tz');

    scheduleAuth($user);

    $this->postJson('/api/schedules', [
        ...basePayload($vibe->id),
        'timezone' => 'Not/ATimezone',
    ], scheduleHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['timezone']);
});

test('monthly recurrence_type returns 422', function () {
    [$user, $vibe] = userWithVibe('fb-sch-val-monthly');

    scheduleAuth($user);

    $this->postJson('/api/schedules', [
        ...basePayload($vibe->id),
        'recurrence_type' => RecurrenceType::Monthly->value,
    ], scheduleHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence_type']);
});

test('custom recurrence_type returns 422', function () {
    [$user, $vibe] = userWithVibe('fb-sch-val-custom');

    scheduleAuth($user);

    $this->postJson('/api/schedules', [
        ...basePayload($vibe->id),
        'recurrence_type' => 'custom',
    ], scheduleHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence_type']);
});

test('none recurrence_type returns 422', function () {
    [$user, $vibe] = userWithVibe('fb-sch-val-none');

    scheduleAuth($user);

    $this->postJson('/api/schedules', [
        ...basePayload($vibe->id),
        'recurrence_type' => 'none',
    ], scheduleHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence_type']);
});

test('unknown recurrence_type returns 422', function () {
    [$user, $vibe] = userWithVibe('fb-sch-val-unknown-rt');

    scheduleAuth($user);

    $this->postJson('/api/schedules', [
        ...basePayload($vibe->id),
        'recurrence_type' => 'FREQ=DAILY;BYDAY=MO',
    ], scheduleHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence_type']);
});

test('weekly without days_of_week returns 422', function () {
    [$user, $vibe] = userWithVibe('fb-sch-val-weekly-nodays');

    scheduleAuth($user);

    $this->postJson('/api/schedules', [
        ...basePayload($vibe->id),
        'recurrence_type' => RecurrenceType::Weekly->value,
    ], scheduleHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence_config.days_of_week']);
});

test('weekly with day 0 returns 422', function () {
    [$user, $vibe] = userWithVibe('fb-sch-val-weekly-day0');

    scheduleAuth($user);

    $this->postJson('/api/schedules', [
        ...basePayload($vibe->id),
        'recurrence_type' => RecurrenceType::Weekly->value,
        'recurrence_config' => ['days_of_week' => [0, 1]],
    ], scheduleHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence_config.days_of_week.0']);
});

test('weekly with day 8 returns 422', function () {
    [$user, $vibe] = userWithVibe('fb-sch-val-weekly-day8');

    scheduleAuth($user);

    $this->postJson('/api/schedules', [
        ...basePayload($vibe->id),
        'recurrence_type' => RecurrenceType::Weekly->value,
        'recurrence_config' => ['days_of_week' => [8]],
    ], scheduleHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence_config.days_of_week.0']);
});

test('server-controlled next_run_at in payload is ignored and computed', function () {
    [$user, $vibe] = userWithVibe('fb-sch-store-nra-inject');

    scheduleAuth($user);

    $injectedAt = '2000-01-01T00:00:00.000000Z';

    $response = $this->postJson('/api/schedules', [
        ...basePayload($vibe->id),
        'next_run_at' => $injectedAt,
    ], scheduleHeaders())->assertCreated();

    // next_run_at must be computed, not the injected value.
    expect($response->json('data.next_run_at'))->not->toBe($injectedAt);

    $schedule = Schedule::findOrFail($response->json('data.id'));
    expect($schedule->next_run_at->year)->toBeGreaterThan(2000);
});

test('server-controlled last_run_at in payload is ignored', function () {
    [$user, $vibe] = userWithVibe('fb-sch-store-lra-inject');

    scheduleAuth($user);

    $response = $this->postJson('/api/schedules', [
        ...basePayload($vibe->id),
        'last_run_at' => '2000-01-01T00:00:00.000000Z',
    ], scheduleHeaders())->assertCreated();

    $schedule = Schedule::findOrFail($response->json('data.id'));
    expect($schedule->last_run_at)->toBeNull();
});

test('missing required fields returns 422', function () {
    [$user] = userWithVibe('fb-sch-store-missing');

    scheduleAuth($user);

    $this->postJson('/api/schedules', [], scheduleHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vibe_id', 'name', 'timezone', 'start_time', 'recurrence_type']);
});

// ────────────────────────────────────────────────────────────────────────────
// Update
// ────────────────────────────────────────────────────────────────────────────

test('user can update own schedule name', function () {
    [$user, $vibe] = userWithVibe('fb-sch-upd-name');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create(['name' => 'Before']);

    scheduleAuth($user);

    $this->patchJson("/api/schedules/{$schedule->id}", ['name' => 'After'], scheduleHeaders())
        ->assertOk()
        ->assertJsonPath('data.name', 'After');

    expect($schedule->fresh()->name)->toBe('After');
});

test('updating recurrence fields recomputes next_run_at', function () {
    [$user, $vibe] = userWithVibe('fb-sch-upd-recalc');

    $schedule = Schedule::factory()->once()->for($user, 'user')->for($vibe, 'vibe')->create([
        'start_time' => now()->addHour(),
        'next_run_at' => now()->addHour(),
    ]);

    $originalNra = $schedule->next_run_at;

    scheduleAuth($user);

    $newStart = CarbonImmutable::now('UTC')->addDays(5)->toDateTimeString();

    $response = $this->patchJson("/api/schedules/{$schedule->id}", [
        'recurrence_type' => RecurrenceType::Daily->value,
        'start_time' => $newStart,
    ], scheduleHeaders())->assertOk();

    $fresh = $schedule->fresh();
    expect($fresh->next_run_at)->not->toBeNull()
        ->and($fresh->next_run_at->notEqualTo($originalNra))->toBeTrue();
});

test('disabling schedule sets next_run_at to null', function () {
    [$user, $vibe] = userWithVibe('fb-sch-upd-disable');

    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create([
        'is_enabled' => true,
        'next_run_at' => now()->addHour(),
    ]);

    scheduleAuth($user);

    $this->patchJson("/api/schedules/{$schedule->id}", ['is_enabled' => false], scheduleHeaders())
        ->assertOk()
        ->assertJsonPath('data.is_enabled', false)
        ->assertJsonPath('data.next_run_at', null);

    expect($schedule->fresh()->next_run_at)->toBeNull();
});

test('re-enabling schedule recomputes next_run_at', function () {
    [$user, $vibe] = userWithVibe('fb-sch-upd-reenable');

    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create([
        'is_enabled' => false,
        'next_run_at' => null,
        'start_time' => now()->addHours(3),
    ]);

    scheduleAuth($user);

    $response = $this->patchJson("/api/schedules/{$schedule->id}", ['is_enabled' => true], scheduleHeaders())
        ->assertOk()
        ->assertJsonPath('data.is_enabled', true);

    expect($response->json('data.next_run_at'))->not->toBeNull();
});

test('user cannot update another users schedule', function () {
    [$alice] = userWithVibe('fb-sch-upd-xuser-alice');
    [$bob, $bobVibe] = userWithVibe('fb-sch-upd-xuser-bob');

    $bobSchedule = Schedule::factory()->daily()->for($bob, 'user')->for($bobVibe, 'vibe')->create(['name' => 'Original']);

    scheduleAuth($alice);

    $this->patchJson("/api/schedules/{$bobSchedule->id}", ['name' => 'Stolen'], scheduleHeaders())
        ->assertForbidden();

    expect($bobSchedule->fresh()->name)->toBe('Original');
});

test('cannot update schedule to use another users vibe', function () {
    [$alice, $aliceVibe] = userWithVibe('fb-sch-upd-xvibe-alice');
    [$bob, $bobVibe] = userWithVibe('fb-sch-upd-xvibe-bob');

    $schedule = Schedule::factory()->daily()->for($alice, 'user')->for($aliceVibe, 'vibe')->create();

    scheduleAuth($alice);

    $this->patchJson("/api/schedules/{$schedule->id}", ['vibe_id' => $bobVibe->id], scheduleHeaders())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['vibe_id']);
});

test('monthly rejected on update returns 422', function () {
    [$user, $vibe] = userWithVibe('fb-sch-upd-monthly');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    scheduleAuth($user);

    $this->patchJson("/api/schedules/{$schedule->id}", [
        'recurrence_type' => RecurrenceType::Monthly->value,
    ], scheduleHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence_type']);
});

test('none rejected on update returns 422', function () {
    [$user, $vibe] = userWithVibe('fb-sch-upd-none');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    scheduleAuth($user);

    $this->patchJson("/api/schedules/{$schedule->id}", [
        'recurrence_type' => 'none',
    ], scheduleHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence_type']);
});

test('custom rejected on update returns 422', function () {
    [$user, $vibe] = userWithVibe('fb-sch-upd-custom');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    scheduleAuth($user);

    $this->patchJson("/api/schedules/{$schedule->id}", [
        'recurrence_type' => 'custom',
    ], scheduleHeaders())->assertUnprocessable()
        ->assertJsonValidationErrors(['recurrence_type']);
});

// ────────────────────────────────────────────────────────────────────────────
// Destroy
// ────────────────────────────────────────────────────────────────────────────

test('user can delete own schedule', function () {
    [$user, $vibe] = userWithVibe('fb-sch-del-own');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    scheduleAuth($user);

    $this->deleteJson("/api/schedules/{$schedule->id}", [], scheduleHeaders())
        ->assertNoContent();

    expect(Schedule::find($schedule->id))->toBeNull();
});

test('user cannot delete another users schedule', function () {
    [$alice] = userWithVibe('fb-sch-del-alice');
    [$bob, $bobVibe] = userWithVibe('fb-sch-del-bob');

    $bobSchedule = Schedule::factory()->daily()->for($bob, 'user')->for($bobVibe, 'vibe')->create();

    scheduleAuth($alice);

    $this->deleteJson("/api/schedules/{$bobSchedule->id}", [], scheduleHeaders())
        ->assertForbidden();

    expect(Schedule::find($bobSchedule->id))->not->toBeNull();
});

// ────────────────────────────────────────────────────────────────────────────
// Resource shape
// ────────────────────────────────────────────────────────────────────────────

test('ScheduleResource returns all expected fields', function () {
    [$user, $vibe] = userWithVibe('fb-sch-resource');

    $schedule = Schedule::factory()->weekly([1, 3, 5])->for($user, 'user')->for($vibe, 'vibe')->create([
        'name' => 'Resource Test',
        'timezone' => 'America/Sao_Paulo',
    ]);

    scheduleAuth($user);

    $this->getJson("/api/schedules/{$schedule->id}", scheduleHeaders())
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
            ],
        ])
        ->assertJsonPath('data.timezone', 'America/Sao_Paulo')
        ->assertJsonPath('data.recurrence_type', 'weekly')
        ->assertJsonPath('data.recurrence_config.days_of_week', [1, 3, 5]);
});

test('ScheduleResource does not expose user_id', function () {
    [$user, $vibe] = userWithVibe('fb-sch-no-userid');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    scheduleAuth($user);

    $response = $this->getJson("/api/schedules/{$schedule->id}", scheduleHeaders())->assertOk();

    expect($response->json('data'))->not->toHaveKey('user_id');
});

test('dates are serialized as ISO 8601 strings', function () {
    [$user, $vibe] = userWithVibe('fb-sch-dates');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    scheduleAuth($user);

    $response = $this->getJson("/api/schedules/{$schedule->id}", scheduleHeaders())->assertOk();

    $data = $response->json('data');

    // ISO 8601 pattern: e.g. 2025-06-12T14:00:00.000000Z
    $isoPattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/';

    expect($data['created_at'])->toMatch($isoPattern)
        ->and($data['updated_at'])->toMatch($isoPattern)
        ->and($data['start_time'])->toMatch($isoPattern);
});
