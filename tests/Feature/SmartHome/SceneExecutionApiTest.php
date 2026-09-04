<?php

declare(strict_types=1);

use App\Models\Scene;
use App\Models\SceneActionExecution;
use App\Models\User;
use App\SmartHome\SceneExecutionAggregateState;
use App\SmartHome\Services\SceneExecutionAggregationService;
use App\Telemetry\SmartHome\SmartHomeActionOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function seJwt(User $user): UnencryptedToken
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

function seAuth(User $user): void
{
    test()->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->andReturn(seJwt($user)));
}

function seHeaders(): array
{
    return ['Authorization' => 'Bearer tok'];
}

function seUser(?string $uid = null): User
{
    return User::factory()->create(['firebase_uid' => $uid ?? 'fb-se-'.uniqid()]);
}

/**
 * @param  list<array<string, mixed>>  $rows
 */
function seExecutionEvent(Scene $scene, array $rows): string
{
    $executionId = (string) Str::uuid();

    foreach ($rows as $row) {
        SceneActionExecution::factory()->create(array_merge([
            'scene_id' => $scene->id,
            'scene_execution_id' => $executionId,
            'trace_id' => 'operator-trace-'.fake()->uuid(),
        ], $row));
    }

    return $executionId;
}

// ─────────────────────────────────────────────────────────────────────────────
// Authorization
// ─────────────────────────────────────────────────────────────────────────────

test('foreign user receives 404 on execution list', function () {
    $owner = seUser('fb-se-list-owner');
    $other = seUser('fb-se-list-other');
    $scene = Scene::factory()->create(['user_id' => $owner->id]);

    seExecutionEvent($scene, [
        ['outcome' => SmartHomeActionOutcome::Success->value],
    ]);

    seAuth($other);

    $this->getJson("/api/scenes/{$scene->id}/executions", seHeaders())->assertNotFound();
});

test('foreign user receives 404 on execution detail', function () {
    $owner = seUser('fb-se-detail-owner');
    $other = seUser('fb-se-detail-other');
    $scene = Scene::factory()->create(['user_id' => $owner->id]);
    $executionId = seExecutionEvent($scene, [
        ['outcome' => SmartHomeActionOutcome::Success->value],
    ]);

    seAuth($other);

    $this->getJson("/api/scenes/{$scene->id}/executions/{$executionId}", seHeaders())
        ->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Aggregate states
// ─────────────────────────────────────────────────────────────────────────────

test('mixed outcomes return partial_success never success or failure', function () {
    $user = seUser('fb-se-partial');
    $scene = Scene::factory()->create(['user_id' => $user->id]);

    $executionId = seExecutionEvent($scene, [
        ['outcome' => SmartHomeActionOutcome::Success->value, 'provider' => 'home_assistant'],
        ['outcome' => SmartHomeActionOutcome::Success->value, 'provider' => 'home_assistant'],
        ['outcome' => SmartHomeActionOutcome::Failure->value, 'provider' => 'provider_c'],
    ]);

    seAuth($user);

    $this->getJson("/api/scenes/{$scene->id}/executions/{$executionId}", seHeaders())
        ->assertOk()
        ->assertJsonPath('data.state', SceneExecutionAggregateState::PartialSuccess->value)
        ->assertJsonPath('data.count_success', 2)
        ->assertJsonPath('data.count_non_success', 1)
        ->assertJsonPath('data.count_total', 3);

    $list = $this->getJson("/api/scenes/{$scene->id}/executions", seHeaders())->assertOk();

    expect($list->json('data.0.state'))->toBe(SceneExecutionAggregateState::PartialSuccess->value)
        ->and($list->json('data.0.state'))->not->toBe(SceneExecutionAggregateState::Success->value)
        ->and($list->json('data.0.state'))->not->toBe(SceneExecutionAggregateState::Failure->value);
});

test('all success outcomes return success state', function () {
    $user = seUser('fb-se-all-success');
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $executionId = seExecutionEvent($scene, [
        ['outcome' => SmartHomeActionOutcome::Success->value],
        ['outcome' => SmartHomeActionOutcome::Success->value],
    ]);

    seAuth($user);

    $this->getJson("/api/scenes/{$scene->id}/executions/{$executionId}", seHeaders())
        ->assertOk()
        ->assertJsonPath('data.state', SceneExecutionAggregateState::Success->value);
});

test('all non-success outcomes return failure state', function () {
    $user = seUser('fb-se-all-failure');
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $executionId = seExecutionEvent($scene, [
        ['outcome' => SmartHomeActionOutcome::Failure->value],
        ['outcome' => SmartHomeActionOutcome::Unsupported->value],
    ]);

    seAuth($user);

    $this->getJson("/api/scenes/{$scene->id}/executions/{$executionId}", seHeaders())
        ->assertOk()
        ->assertJsonPath('data.state', SceneExecutionAggregateState::Failure->value);
});

test('scene with no execution rows returns empty paginated list', function () {
    $user = seUser('fb-se-no-rows');
    $scene = Scene::factory()->create(['user_id' => $user->id]);

    seAuth($user);

    $this->getJson("/api/scenes/{$scene->id}/executions", seHeaders())
        ->assertOk()
        ->assertJsonPath('data', []);
});

test('aggregate state resolver returns no_actions when count total is zero', function () {
    expect(SceneExecutionAggregateState::fromCounts(0, 0, 0))
        ->toBe(SceneExecutionAggregateState::NoActions);
});

// ─────────────────────────────────────────────────────────────────────────────
// by_provider breakdown
// ─────────────────────────────────────────────────────────────────────────────

test('by_provider reflects success and non-success counts per provider slug', function () {
    $user = seUser('fb-se-by-provider');
    $scene = Scene::factory()->create(['user_id' => $user->id]);

    $executionId = seExecutionEvent($scene, [
        ['outcome' => SmartHomeActionOutcome::Success->value, 'provider' => 'home_assistant'],
        ['outcome' => SmartHomeActionOutcome::Success->value, 'provider' => 'home_assistant'],
        ['outcome' => SmartHomeActionOutcome::Failure->value, 'provider' => 'provider_c'],
    ]);

    seAuth($user);

    $response = $this->getJson("/api/scenes/{$scene->id}/executions/{$executionId}", seHeaders())
        ->assertOk();

    $byProvider = collect($response->json('data.by_provider'))->keyBy('provider');

    expect($byProvider['home_assistant']['count_success'])->toBe(2)
        ->and($byProvider['home_assistant']['count_non_success'])->toBe(0)
        ->and($byProvider['provider_c']['count_success'])->toBe(0)
        ->and($byProvider['provider_c']['count_non_success'])->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// trace_id never exposed
// ─────────────────────────────────────────────────────────────────────────────

test('execution list and detail never expose trace_id', function () {
    $user = seUser('fb-se-no-trace');
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $executionId = seExecutionEvent($scene, [
        ['outcome' => SmartHomeActionOutcome::Success->value, 'trace_id' => 'secret-trace-abc123'],
    ]);

    seAuth($user);

    $list = $this->getJson("/api/scenes/{$scene->id}/executions", seHeaders())->assertOk();
    $detail = $this->getJson("/api/scenes/{$scene->id}/executions/{$executionId}", seHeaders())->assertOk();

    expect(json_encode($list->json()))->not->toContain('secret-trace-abc123')
        ->and(json_encode($list->json()))->not->toContain('trace_id')
        ->and(json_encode($detail->json()))->not->toContain('secret-trace-abc123')
        ->and(json_encode($detail->json()))->not->toContain('trace_id')
        ->and($detail->json('data.actions.0'))->not->toHaveKey('trace_id');
});

// ─────────────────────────────────────────────────────────────────────────────
// List ordering and pagination
// ─────────────────────────────────────────────────────────────────────────────

test('execution list returns most recent events first and supports pagination', function () {
    $user = seUser('fb-se-pagination');
    $scene = Scene::factory()->create(['user_id' => $user->id]);

    $olderId = seExecutionEvent($scene, [
        ['outcome' => SmartHomeActionOutcome::Success->value, 'executed_at' => now()->subHour()],
    ]);
    $newerId = seExecutionEvent($scene, [
        ['outcome' => SmartHomeActionOutcome::Success->value, 'executed_at' => now()],
    ]);

    seAuth($user);

    $page1 = $this->getJson("/api/scenes/{$scene->id}/executions?per_page=1", seHeaders())
        ->assertOk()
        ->assertJsonPath('meta.per_page', 1)
        ->assertJsonPath('meta.total', 2);

    expect($page1->json('data.0.scene_execution_id'))->toBe($newerId);

    $page2 = $this->getJson("/api/scenes/{$scene->id}/executions?per_page=1&page=2", seHeaders())
        ->assertOk();

    expect($page2->json('data.0.scene_execution_id'))->toBe($olderId);
});

// ─────────────────────────────────────────────────────────────────────────────
// 404 boundaries
// ─────────────────────────────────────────────────────────────────────────────

test('execution detail returns 404 for unknown scene_execution_id', function () {
    $user = seUser('fb-se-unknown-exec');
    $scene = Scene::factory()->create(['user_id' => $user->id]);

    seAuth($user);

    $this->getJson('/api/scenes/'.$scene->id.'/executions/'.Str::uuid(), seHeaders())
        ->assertNotFound();
});

test('execution detail returns 404 when event belongs to another scene', function () {
    $user = seUser('fb-se-wrong-scene');
    $sceneA = Scene::factory()->create(['user_id' => $user->id]);
    $sceneB = Scene::factory()->create(['user_id' => $user->id]);

    $executionOnB = seExecutionEvent($sceneB, [
        ['outcome' => SmartHomeActionOutcome::Success->value],
    ]);

    seAuth($user);

    $this->getJson("/api/scenes/{$sceneA->id}/executions/{$executionOnB}", seHeaders())
        ->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// SQL aggregation (not PHP loop)
// ─────────────────────────────────────────────────────────────────────────────

test('aggregation service uses SQL conditional counts not PHP iteration', function () {
    $user = seUser('fb-se-sql');
    $scene = Scene::factory()->create(['user_id' => $user->id]);
    $executionId = seExecutionEvent($scene, [
        ['outcome' => SmartHomeActionOutcome::Success->value],
        ['outcome' => SmartHomeActionOutcome::Failure->value],
    ]);

    $capturedSql = [];

    DB::listen(function ($query) use (&$capturedSql) {
        if (str_contains($query->sql, 'scene_action_executions')) {
            $capturedSql[] = $query->sql;
        }
    });

    app(SceneExecutionAggregationService::class)->summarizeExecution($scene->id, $executionId);

    expect($capturedSql)->not->toBeEmpty();

    $aggregateQuery = collect($capturedSql)->first(
        fn (string $sql) => str_contains($sql, 'CASE WHEN') && str_contains($sql, 'count_success')
    );

    expect($aggregateQuery)->not->toBeNull()
        ->and($aggregateQuery)->toContain('SUM(CASE WHEN outcome = \'success\' THEN 1 ELSE 0 END)');
});
