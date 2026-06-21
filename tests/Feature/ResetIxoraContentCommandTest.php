<?php

declare(strict_types=1);

use App\Models\AdminAccessRequest;
use App\Models\CoverBundle;
use App\Models\PresetVibe;
use App\Models\Sound;
use App\Models\User;
use App\Models\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/** @param  non-empty-string  $userFirebaseUid */
function seedIxoraContent(User $user): void
{
    $sound = Sound::query()->create([
        'name' => 'S',
        'file_url' => 'https://cdn.example/a.mp3',
        'category' => 'x',
        'duration' => 1,
        'tags' => [],
        'is_active' => true,
    ]);

    $cover = CoverBundle::query()->create([
        'name' => 'C',
        'description' => null,
        'thumbnail_url' => null,
        'artwork_url' => null,
        'player_background_url' => null,
        'category' => null,
        'tags' => [],
        'is_active' => true,
    ]);

    $preset = PresetVibe::query()->create([
        'name' => 'P',
        'description' => null,
        'cover_bundle_id' => $cover->id,
        'category' => null,
        'tags' => [],
        'is_active' => true,
    ]);

    DB::table('preset_vibe_sounds')->insert([
        'preset_vibe_id' => $preset->id,
        'sound_id' => $sound->id,
        'volume' => 80,
        'loop' => true,
        'play_mode' => 'loop',
        'sort_order' => 0,
        'repeat_interval_seconds' => null,
        'start_offset_seconds' => null,
        'play_duration_seconds' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $vibe = Vibe::query()->create([
        'user_id' => $user->id,
        'name' => 'V',
        'description' => null,
        'is_active' => true,
    ]);

    DB::table('vibe_sounds')->insert([
        'vibe_id' => $vibe->id,
        'sound_id' => $sound->id,
        'volume' => 80,
        'loop' => true,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

test('ixora:reset-content without --confirm aborts and preserves data', function (): void {
    $user = User::factory()->create();
    seedIxoraContent($user);

    $exit = Artisan::call('ixora:reset-content');

    expect($exit)->not->toBe(0)
        ->and(Sound::query()->count())->toBe(1)
        ->and(Vibe::query()->count())->toBe(1);
});

test('ixora:reset-content with --confirm deletes content and preserves users and admin access requests', function (): void {
    $user = User::factory()->create();
    AdminAccessRequest::factory()->create(['user_id' => $user->id]);
    seedIxoraContent($user);

    $exit = Artisan::call('ixora:reset-content', ['--confirm' => true]);

    expect($exit)->toBe(0)
        ->and(User::query()->count())->toBe(1)
        ->and(AdminAccessRequest::query()->count())->toBe(1)
        ->and(Sound::query()->count())->toBe(0)
        ->and(Vibe::query()->count())->toBe(0)
        ->and(CoverBundle::query()->count())->toBe(0)
        ->and(PresetVibe::query()->count())->toBe(0)
        ->and(DB::table('vibe_sounds')->count())->toBe(0)
        ->and(DB::table('preset_vibe_sounds')->count())->toBe(0);
});
