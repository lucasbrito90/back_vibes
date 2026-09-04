<?php

declare(strict_types=1);

use App\Models\SceneActionExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('smart-home:prune-executions removes rows older than 90 days in batches', function () {
    SceneActionExecution::factory()->count(3)->create();
    SceneActionExecution::factory()->count(2)->olderThanDays(91)->create();
    SceneActionExecution::factory()->olderThanDays(120)->create();

    expect(SceneActionExecution::query()->count())->toBe(6);

    $this->artisan('smart-home:prune-executions')->assertSuccessful();

    expect(SceneActionExecution::query()->count())->toBe(3);
});

test('smart-home:prune-executions deletes more than one batch when needed', function () {
    SceneActionExecution::factory()->count(1001)->olderThanDays(100)->create();

    $this->artisan('smart-home:prune-executions')->assertSuccessful();

    expect(SceneActionExecution::query()->count())->toBe(0);
});

test('smart-home:prune-executions preserves recent rows', function () {
    $recent = SceneActionExecution::factory()->create();
    SceneActionExecution::factory()->olderThanDays(95)->create();

    $this->artisan('smart-home:prune-executions')->assertSuccessful();

    expect(SceneActionExecution::query()->pluck('id')->all())->toBe([$recent->id]);
});
