<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Scene;
use App\Models\SceneAction;
use App\Models\User;
use App\SmartHome\ActionType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Schema — scenes / scene_actions tables
// ─────────────────────────────────────────────────────────────────────────────

test('scenes table has expected columns', function () {
    $columns = Schema::getColumnListing('scenes');

    expect($columns)
        ->toContain('id')
        ->toContain('user_id')
        ->toContain('name')
        ->toContain('description')
        ->toContain('created_at')
        ->toContain('updated_at');
});

test('scene_actions table has expected columns', function () {
    $columns = Schema::getColumnListing('scene_actions');

    expect($columns)
        ->toContain('id')
        ->toContain('scene_id')
        ->toContain('device_id')
        ->toContain('action_type')
        ->toContain('parameters')
        ->toContain('delay_seconds')
        ->toContain('sort_order')
        ->toContain('created_at')
        ->toContain('updated_at');
});

// ─────────────────────────────────────────────────────────────────────────────
// Cascade deletes
// ─────────────────────────────────────────────────────────────────────────────

test('deleting a scene cascades to its scene_actions', function () {
    $scene = Scene::factory()->create();
    $action = SceneAction::factory()->create(['scene_id' => $scene->id]);

    $scene->delete();

    expect(SceneAction::find($action->id))->toBeNull();
});

test('deleting a device cascades to its scene_actions', function () {
    $device = Device::factory()->create();
    $action = SceneAction::factory()->create(['device_id' => $device->id]);

    $device->delete();

    expect(SceneAction::find($action->id))->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// ScenePolicy — owner isolation
// ─────────────────────────────────────────────────────────────────────────────

test('scene owner can view update and delete their scene', function () {
    $user = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $user->id]);

    expect(Gate::forUser($user)->allows('view', $scene))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $scene))->toBeTrue()
        ->and(Gate::forUser($user)->allows('delete', $scene))->toBeTrue();
});

test('non-owner cannot view update or delete another users scene', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $owner->id]);

    expect(Gate::forUser($other)->denies('view', $scene))->toBeTrue()
        ->and(Gate::forUser($other)->denies('update', $scene))->toBeTrue()
        ->and(Gate::forUser($other)->denies('delete', $scene))->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────────
// Scene model — relationships
// ─────────────────────────────────────────────────────────────────────────────

test('SceneActionFactory turnOn state sets action_type turn_on', function () {
    expect(SceneAction::factory()->turnOn()->create()->action_type)->toBe(ActionType::TurnOn->value);
});

test('SceneActionFactory turnOff state sets action_type turn_off', function () {
    expect(SceneAction::factory()->turnOff()->create()->action_type)->toBe(ActionType::TurnOff->value);
});

test('SceneActionFactory toggle state sets action_type toggle', function () {
    expect(SceneAction::factory()->toggle()->create()->action_type)->toBe(ActionType::Toggle->value);
});

test('scene actions relationship returns actions ordered by sort_order', function () {
    $scene = Scene::factory()->create();
    $device = Device::factory()->create(['user_id' => $scene->user_id]);

    SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
        'sort_order' => 2,
    ]);
    SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
        'sort_order' => 0,
    ]);
    SceneAction::factory()->create([
        'scene_id' => $scene->id,
        'device_id' => $device->id,
        'sort_order' => 1,
    ]);

    expect($scene->actions->pluck('sort_order')->all())->toBe([0, 1, 2]);
});
