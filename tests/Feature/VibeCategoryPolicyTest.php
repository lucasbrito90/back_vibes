<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\VibeCategory;
use App\Policies\VibeCategoryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('regular user can view but not mutate vibe categories', function () {
    $user = User::factory()->create();
    $category = VibeCategory::query()->create([
        'slug' => 'sleep',
        'names' => ['en' => 'Sleep'],
        'sort_order' => 0,
        'is_active' => true,
    ]);
    $policy = new VibeCategoryPolicy;

    expect($policy->viewAny($user))->toBeTrue()
        ->and($policy->view($user, $category))->toBeTrue()
        ->and($policy->create($user))->toBeFalse()
        ->and($policy->update($user, $category))->toBeFalse()
        ->and($policy->delete($user, $category))->toBeFalse();
});

test('approved admin can mutate vibe categories', function () {
    $admin = User::factory()->create([
        'role' => 'admin',
        'admin_access_status' => 'approved',
    ]);
    $category = VibeCategory::query()->create([
        'slug' => 'calm',
        'names' => ['en' => 'Calm'],
        'sort_order' => 0,
        'is_active' => true,
    ]);
    $policy = new VibeCategoryPolicy;

    expect($policy->create($admin))->toBeTrue()
        ->and($policy->update($admin, $category))->toBeTrue()
        ->and($policy->delete($admin, $category))->toBeTrue();
});
