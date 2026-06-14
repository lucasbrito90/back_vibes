<?php

declare(strict_types=1);

use App\Models\Schedule;
use App\Models\ScheduleExecution;
use App\Models\User;
use App\Models\Vibe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — prefixed with `exec` to avoid collision with ScheduleApiTest helpers
// ─────────────────────────────────────────────────────────────────────────────

function execJwt(User $user): UnencryptedToken
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

function execAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(execJwt($user)));
}

function execHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function execUserWithVibe(?string $uid = null): array
{
    $user = User::factory()->create(['firebase_uid' => $uid ?? 'fb-exec-'.uniqid()]);
    $vibe = Vibe::factory()->for($user)->create();

    return [$user, $vibe];
}

function execScheduleWithExecutions(User $user, Vibe $vibe, int $count = 3): array
{
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();
    $now = CarbonImmutable::now('UTC');

    $executions = collect(range(1, $count))->map(fn (int $i) => ScheduleExecution::factory()->create([
        'schedule_id' => $schedule->id,
        'scheduled_for' => $now->subMinutes($i),
        'occurrence_key' => "{$schedule->id}:{$now->subMinutes($i)->timestamp}",
        'status' => 'dispatched',
    ]))->all();

    return [$schedule, $executions];
}

// ─────────────────────────────────────────────────────────────────────────────
// Authentication
// ─────────────────────────────────────────────────────────────────────────────

test('unauthenticated cannot list executions', function () {
    $schedule = Schedule::factory()->create();

    $this->getJson("/api/schedules/{$schedule->id}/executions")
        ->assertUnauthorized();
});

test('unauthenticated cannot ack execution', function () {
    $schedule = Schedule::factory()->create();

    $this->postJson("/api/schedules/{$schedule->id}/executions/1:1000000000/ack")
        ->assertUnauthorized();
});

// ─────────────────────────────────────────────────────────────────────────────
// List executions — GET /api/schedules/{schedule}/executions
// ─────────────────────────────────────────────────────────────────────────────

test('owner can list executions for their schedule', function () {
    [$user, $vibe] = execUserWithVibe('fb-exec-list-owner');
    [$schedule, $executions] = execScheduleWithExecutions($user, $vibe, 2);

    execAuth($user);

    $response = $this->getJson("/api/schedules/{$schedule->id}/executions", execHeaders())
        ->assertOk();

    expect($response->json('data'))->toHaveCount(2);
});

test('list response includes required execution fields', function () {
    [$user, $vibe] = execUserWithVibe('fb-exec-fields');
    [$schedule] = execScheduleWithExecutions($user, $vibe, 1);

    execAuth($user);

    $response = $this->getJson("/api/schedules/{$schedule->id}/executions", execHeaders())
        ->assertOk();

    $item = $response->json('data.0');
    expect($item)->toHaveKeys([
        'id',
        'schedule_id',
        'occurrence_key',
        'scheduled_for',
        'executed_at',
        'status',
        'log',
        'created_at',
    ]);
});

test('cross-user cannot list executions — returns 403', function () {
    [$alice, $aliceVibe] = execUserWithVibe('fb-exec-cross-alice');
    [$bob] = execUserWithVibe('fb-exec-cross-bob');
    [$aliceSchedule] = execScheduleWithExecutions($alice, $aliceVibe, 1);

    execAuth($bob);

    $this->getJson("/api/schedules/{$aliceSchedule->id}/executions", execHeaders())
        ->assertForbidden();
});

test('list is ordered by scheduled_for descending', function () {
    [$user, $vibe] = execUserWithVibe('fb-exec-order');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    $now = CarbonImmutable::now('UTC');
    $old = ScheduleExecution::factory()->forSchedule($schedule)->create([
        'scheduled_for' => $now->subHours(3),
        'occurrence_key' => "{$schedule->id}:{$now->subHours(3)->timestamp}",
    ]);
    $recent = ScheduleExecution::factory()->forSchedule($schedule)->create([
        'scheduled_for' => $now->subHour(),
        'occurrence_key' => "{$schedule->id}:{$now->subHour()->timestamp}",
    ]);

    execAuth($user);

    $response = $this->getJson("/api/schedules/{$schedule->id}/executions", execHeaders())
        ->assertOk();

    $ids = collect($response->json('data'))->pluck('id')->all();
    expect($ids[0])->toBe($recent->id)
        ->and($ids[1])->toBe($old->id);
});

test('list is paginated', function () {
    [$user, $vibe] = execUserWithVibe('fb-exec-paginate');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    for ($i = 1; $i <= 25; $i++) {
        $scheduledFor = CarbonImmutable::now('UTC')->subMinutes($i);
        ScheduleExecution::factory()->forSchedule($schedule)->create([
            'scheduled_for' => $scheduledFor,
            'occurrence_key' => "{$schedule->id}:{$scheduledFor->timestamp}-{$i}",
        ]);
    }

    execAuth($user);

    $response = $this->getJson("/api/schedules/{$schedule->id}/executions", execHeaders())
        ->assertOk();

    expect($response->json('data'))->toHaveCount(20);
    expect($response->json('meta.total'))->toBe(25);
});

// ─────────────────────────────────────────────────────────────────────────────
// Acknowledge — POST /api/schedules/{schedule}/executions/{occurrence_key}/ack
// ─────────────────────────────────────────────────────────────────────────────

test('owner can ack an execution — status becomes acknowledged', function () {
    [$user, $vibe] = execUserWithVibe('fb-exec-ack-owner');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    $scheduledFor = CarbonImmutable::now('UTC')->subMinutes(5);
    $occurrenceKey = "{$schedule->id}:{$scheduledFor->timestamp}";
    $execution = ScheduleExecution::factory()->forSchedule($schedule)->create([
        'scheduled_for' => $scheduledFor,
        'occurrence_key' => $occurrenceKey,
        'status' => 'dispatched',
    ]);

    execAuth($user);

    $response = $this->postJson(
        "/api/schedules/{$schedule->id}/executions/{$occurrenceKey}/ack",
        [],
        execHeaders(),
    )->assertOk();

    expect($response->json('data.status'))->toBe('acknowledged');
    expect($execution->fresh()->status)->toBe('acknowledged');
});

test('ack is idempotent — second call returns 200 and does not duplicate', function () {
    [$user, $vibe] = execUserWithVibe('fb-exec-ack-idem');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    $scheduledFor = CarbonImmutable::now('UTC')->subMinutes(5);
    $occurrenceKey = "{$schedule->id}:{$scheduledFor->timestamp}";
    ScheduleExecution::factory()->forSchedule($schedule)->create([
        'scheduled_for' => $scheduledFor,
        'occurrence_key' => $occurrenceKey,
        'status' => 'acknowledged',
    ]);

    execAuth($user);

    $this->postJson(
        "/api/schedules/{$schedule->id}/executions/{$occurrenceKey}/ack",
        [],
        execHeaders(),
    )->assertOk();

    $this->postJson(
        "/api/schedules/{$schedule->id}/executions/{$occurrenceKey}/ack",
        [],
        execHeaders(),
    )->assertOk();

    expect(ScheduleExecution::query()->where('occurrence_key', $occurrenceKey)->count())->toBe(1);
});

test('ack on already-acknowledged execution does not change status again', function () {
    [$user, $vibe] = execUserWithVibe('fb-exec-ack-already');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    $scheduledFor = CarbonImmutable::now('UTC')->subMinutes(5);
    $occurrenceKey = "{$schedule->id}:{$scheduledFor->timestamp}";
    $execution = ScheduleExecution::factory()->forSchedule($schedule)->create([
        'scheduled_for' => $scheduledFor,
        'occurrence_key' => $occurrenceKey,
        'status' => 'acknowledged',
    ]);

    execAuth($user);

    $this->postJson(
        "/api/schedules/{$schedule->id}/executions/{$occurrenceKey}/ack",
        [],
        execHeaders(),
    )->assertOk();

    expect($execution->fresh()->status)->toBe('acknowledged');
});

test('cross-user cannot ack execution — returns 403', function () {
    [$alice, $aliceVibe] = execUserWithVibe('fb-exec-ack-cross-alice');
    [$bob] = execUserWithVibe('fb-exec-ack-cross-bob');

    $schedule = Schedule::factory()->daily()->for($alice, 'user')->for($aliceVibe, 'vibe')->create();
    $scheduledFor = CarbonImmutable::now('UTC')->subMinutes(5);
    $occurrenceKey = "{$schedule->id}:{$scheduledFor->timestamp}";
    ScheduleExecution::factory()->forSchedule($schedule)->create([
        'scheduled_for' => $scheduledFor,
        'occurrence_key' => $occurrenceKey,
    ]);

    execAuth($bob);

    $this->postJson(
        "/api/schedules/{$schedule->id}/executions/{$occurrenceKey}/ack",
        [],
        execHeaders(),
    )->assertForbidden();
});

test('unknown occurrence_key returns 404', function () {
    [$user, $vibe] = execUserWithVibe('fb-exec-ack-404');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    execAuth($user);

    $this->postJson(
        "/api/schedules/{$schedule->id}/executions/{$schedule->id}:9999999999/ack",
        [],
        execHeaders(),
    )->assertNotFound();
});

test('ack does not imply or trigger playback — status is only acknowledged', function () {
    [$user, $vibe] = execUserWithVibe('fb-exec-ack-no-play');
    $schedule = Schedule::factory()->daily()->for($user, 'user')->for($vibe, 'vibe')->create();

    $scheduledFor = CarbonImmutable::now('UTC')->subMinutes(5);
    $occurrenceKey = "{$schedule->id}:{$scheduledFor->timestamp}";
    $execution = ScheduleExecution::factory()->forSchedule($schedule)->create([
        'scheduled_for' => $scheduledFor,
        'occurrence_key' => $occurrenceKey,
        'status' => 'dispatched',
    ]);

    execAuth($user);

    $response = $this->postJson(
        "/api/schedules/{$schedule->id}/executions/{$occurrenceKey}/ack",
        [],
        execHeaders(),
    )->assertOk();

    // Only status changes — no new executions, no playback record, no extra rows
    expect($response->json('data.status'))->toBe('acknowledged');
    expect(ScheduleExecution::query()->where('schedule_id', $schedule->id)->count())->toBe(1);
    expect($execution->fresh()->occurrence_key)->toBe($occurrenceKey);
});
