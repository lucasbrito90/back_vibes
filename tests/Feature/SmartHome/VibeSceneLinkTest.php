<?php

declare(strict_types=1);

use App\Models\Scene;
use App\Models\User;
use App\Models\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

// ─────────────────────────────────────────────────────────────────────────────
// Schema — vibes.scene_id
// ─────────────────────────────────────────────────────────────────────────────

test('vibes table has nullable scene_id column', function () {
    $columns = Schema::getColumnListing('vibes');

    expect($columns)->toContain('scene_id');
});

test('deleting a scene nulls scene_id on linked vibes instead of deleting them', function () {
    $user = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $user->id]);

    $vibeA = Vibe::factory()->create(['user_id' => $user->id, 'scene_id' => $scene->id]);
    $vibeB = Vibe::factory()->create(['user_id' => $user->id, 'scene_id' => $scene->id]);
    $unlinked = Vibe::factory()->create(['user_id' => $user->id, 'scene_id' => null]);

    $scene->delete();

    expect($vibeA->fresh()->scene_id)->toBeNull()
        ->and($vibeB->fresh()->scene_id)->toBeNull()
        ->and(Vibe::find($vibeA->id))->not->toBeNull()
        ->and(Vibe::find($vibeB->id))->not->toBeNull()
        ->and($unlinked->fresh()->scene_id)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// Model — Vibe::scene()
// ─────────────────────────────────────────────────────────────────────────────

test('Vibe scene relationship resolves the linked Scene', function () {
    $user = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $user->id, 'name' => 'Movie Night']);
    $vibe = Vibe::factory()->create(['user_id' => $user->id, 'scene_id' => $scene->id]);

    expect($vibe->scene)->toBeInstanceOf(Scene::class)
        ->and($vibe->scene->id)->toBe($scene->id)
        ->and($vibe->scene->name)->toBe('Movie Night');
});

test('Vibe scene relationship is null when scene_id is not set', function () {
    $vibe = Vibe::factory()->create(['scene_id' => null]);

    expect($vibe->scene)->toBeNull();
});

test('multiple vibes can reference the same scene', function () {
    $user = User::factory()->create();
    $scene = Scene::factory()->create(['user_id' => $user->id]);

    $vibeOne = Vibe::factory()->create(['user_id' => $user->id, 'scene_id' => $scene->id]);
    $vibeTwo = Vibe::factory()->create(['user_id' => $user->id, 'scene_id' => $scene->id]);

    expect($vibeOne->scene?->id)->toBe($scene->id)
        ->and($vibeTwo->scene?->id)->toBe($scene->id);
});
