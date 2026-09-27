<?php

declare(strict_types=1);

use App\Models\PresetVibe;
use App\Models\User;
use App\Models\Vibe;
use App\Models\VibeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeVibeCategory(array $overrides = []): VibeCategory
{
    return VibeCategory::query()->create(array_merge([
        'slug' => 'cat-'.uniqid(),
        'names' => ['en' => 'Category'],
        'sort_order' => 0,
        'is_active' => true,
    ], $overrides));
}

test('vibe and vibe category are linked both ways', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->for($user)->create();
    $category = makeVibeCategory(['slug' => 'focus', 'names' => ['en' => 'Focus']]);

    $vibe->categories()->attach($category->id);

    expect($vibe->fresh()->categories)->toHaveCount(1)
        ->and($vibe->categories->first()->id)->toBe($category->id)
        ->and($category->fresh()->vibes)->toHaveCount(1)
        ->and($category->vibes->first()->id)->toBe($vibe->id);
});

test('preset vibe and vibe category sync via pivot', function () {
    $preset = PresetVibe::query()->create([
        'name' => 'Template',
        'is_active' => true,
    ]);
    $c1 = makeVibeCategory(['slug' => 'a', 'sort_order' => 1]);
    $c2 = makeVibeCategory(['slug' => 'b', 'sort_order' => 2]);

    $preset->categories()->sync([$c1->id, $c2->id]);

    expect($preset->fresh()->categories)->toHaveCount(2);

    $preset->categories()->detach($c1->id);

    expect($preset->fresh()->categories)->toHaveCount(1)
        ->and($preset->categories->first()->id)->toBe($c2->id)
        ->and($c2->fresh()->presetVibes)->toHaveCount(1);
});

test('scopeActive returns only active categories ordered by sort_order then id', function () {
    $inactive = makeVibeCategory(['slug' => 'off', 'sort_order' => 0, 'is_active' => false]);
    $second = makeVibeCategory(['slug' => 'second', 'sort_order' => 20]);
    $first = makeVibeCategory(['slug' => 'first', 'sort_order' => 10]);

    $rows = VibeCategory::query()->active()->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('id')->all())->toBe([$first->id, $second->id])
        ->and($rows->pluck('id'))->not->toContain($inactive->id);
});

test('vibe categories relation excludes inactive categories', function () {
    $user = User::factory()->create();
    $vibe = Vibe::factory()->for($user)->create();
    $active = makeVibeCategory(['slug' => 'active', 'sort_order' => 5]);
    $inactive = makeVibeCategory(['slug' => 'inactive', 'sort_order' => 1, 'is_active' => false]);

    $vibe->categories()->attach([$active->id, $inactive->id]);

    $loaded = $vibe->fresh()->categories;

    expect($loaded)->toHaveCount(1)
        ->and($loaded->first()->id)->toBe($active->id);
});
