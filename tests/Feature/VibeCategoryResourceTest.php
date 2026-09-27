<?php

declare(strict_types=1);

use App\Http\Resources\PresetVibeResource;
use App\Http\Resources\VibeResource;
use App\Models\PresetVibe;
use App\Models\User;
use App\Models\Vibe;
use App\Models\VibeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

test('preset vibe resource json has no category key', function () {
    $preset = PresetVibe::query()->create([
        'name' => 'Storm',
        'is_active' => true,
    ]);

    $payload = (new PresetVibeResource($preset))->toArray(Request::create('/'));

    expect($payload)->not->toHaveKey('category');
});

test('vibe resource embeds active categories in sort_order when loaded', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->for($user)->create();
    $later = VibeCategory::query()->create([
        'slug' => 'later',
        'names' => ['en' => 'Later'],
        'sort_order' => 30,
        'is_active' => true,
    ]);
    $earlier = VibeCategory::query()->create([
        'slug' => 'earlier',
        'names' => ['en' => 'Earlier'],
        'sort_order' => 10,
        'is_active' => true,
    ]);
    $hidden = VibeCategory::query()->create([
        'slug' => 'hidden',
        'names' => ['en' => 'Hidden'],
        'sort_order' => 0,
        'is_active' => false,
    ]);

    $vibe->categories()->attach([$later->id, $earlier->id, $hidden->id]);
    $vibe->load('categories');

    $payload = (new VibeResource($vibe))->toArray(Request::create('/'));
    $ids = collect($payload['categories'])->pluck('id')->all();

    expect($ids)->toBe([$earlier->id, $later->id])
        ->and(collect($payload['categories'])->pluck('slug')->all())->toBe(['earlier', 'later']);
});

test('preset vibe resource embeds active categories in sort_order when loaded', function () {
    $preset = PresetVibe::query()->create([
        'name' => 'Kit',
        'is_active' => true,
    ]);
    $b = VibeCategory::query()->create([
        'slug' => 'b-cat',
        'names' => ['en' => 'B'],
        'sort_order' => 20,
        'is_active' => true,
    ]);
    $a = VibeCategory::query()->create([
        'slug' => 'a-cat',
        'names' => ['en' => 'A'],
        'sort_order' => 5,
        'is_active' => true,
    ]);

    $preset->categories()->attach([$b->id, $a->id]);
    $preset->load('categories');

    $payload = (new PresetVibeResource($preset))->toArray(Request::create('/'));

    expect(collect($payload['categories'])->pluck('id')->all())->toBe([$a->id, $b->id]);
});
