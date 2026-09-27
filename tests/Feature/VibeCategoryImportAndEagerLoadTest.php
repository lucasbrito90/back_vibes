<?php

declare(strict_types=1);

use App\Models\PresetVibe;
use App\Models\User;
use App\Models\Vibe;
use App\Models\VibeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Kreait\Firebase\Contract\Auth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;

uses(RefreshDatabase::class);

function jwtForCategoryImportUser(User $user): UnencryptedToken
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

test('import copies only active preset categories and preset changes do not mutate imported vibe', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vc-import-copy']);

    $active = VibeCategory::query()->create([
        'slug' => 'weather',
        'names' => ['en' => 'Weather'],
        'sort_order' => 10,
        'is_active' => true,
    ]);
    $inactive = VibeCategory::query()->create([
        'slug' => 'draft-cat',
        'names' => ['en' => 'Draft'],
        'sort_order' => 0,
        'is_active' => false,
    ]);
    $later = VibeCategory::query()->create([
        'slug' => 'nature',
        'names' => ['en' => 'Nature'],
        'sort_order' => 20,
        'is_active' => true,
    ]);

    $preset = PresetVibe::query()->create([
        'name' => 'Storm Kit',
        'is_active' => true,
    ]);

    DB::table('preset_vibe_vibe_categories')->insert([
        [
            'preset_vibe_id' => $preset->id,
            'vibe_category_id' => $active->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'preset_vibe_id' => $preset->id,
            'vibe_category_id' => $inactive->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCategoryImportUser($user)));

    $response = $this->postJson("/api/preset-vibes/{$preset->id}/import", [], [
        'Authorization' => 'Bearer tok',
    ])->assertCreated();

    $vibeId = (int) $response->json('data.id');
    $vibe = Vibe::query()->findOrFail($vibeId);

    expect($vibe->categories()->pluck('vibe_categories.id')->all())->toBe([$active->id])
        ->and($response->json('data.categories'))->toHaveCount(1)
        ->and($response->json('data.categories.0.slug'))->toBe('weather');

    $preset->categories()->sync([$later->id]);

    expect($vibe->fresh()->categories()->pluck('vibe_categories.id')->all())->toBe([$active->id]);
});

test('manual vibe create has empty categories and no pivot rows', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vc-manual-empty']);

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCategoryImportUser($user)));

    $this->postJson('/api/vibes', [
        'name' => 'Fresh vibe',
    ], ['Authorization' => 'Bearer tok'])
        ->assertCreated()
        ->assertJsonPath('data.categories', []);

    $vibe = Vibe::query()->where('user_id', $user->id)->firstOrFail();
    expect(DB::table('vibe_vibe_categories')->where('vibe_id', $vibe->id)->count())->toBe(0);
});

test('get api vibes list eager loads categories without n plus one', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vc-vibes-n1']);
    $cats = collect([
        VibeCategory::query()->create(['slug' => 'a', 'names' => ['en' => 'A'], 'sort_order' => 0, 'is_active' => true]),
        VibeCategory::query()->create(['slug' => 'b', 'names' => ['en' => 'B'], 'sort_order' => 1, 'is_active' => true]),
    ]);

    foreach (range(1, 5) as $i) {
        $vibe = Vibe::factory()->for($user)->create(['name' => "Vibe {$i}"]);
        $vibe->categories()->attach($cats[$i % 2]->id);
    }

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCategoryImportUser($user)));

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->getJson('/api/vibes', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(5, 'data');

    expect(count(DB::getQueryLog()))->toBeLessThan(12);
});

test('get api preset vibes list eager loads categories without n plus one', function () {
    $user = User::factory()->create(['firebase_uid' => 'fb-vc-preset-n1']);
    $cat = VibeCategory::query()->create(['slug' => 'tag', 'names' => ['en' => 'Tag'], 'sort_order' => 0, 'is_active' => true]);

    foreach (range(1, 5) as $i) {
        $preset = PresetVibe::query()->create(['name' => "Preset {$i}", 'is_active' => true]);
        $preset->categories()->attach($cat->id);
    }

    $this->mock(Auth::class, fn ($m) => $m->shouldReceive('verifyIdToken')->once()->with('tok')->andReturn(jwtForCategoryImportUser($user));

    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->getJson('/api/preset-vibes', ['Authorization' => 'Bearer tok'])
        ->assertOk()
        ->assertJsonCount(5, 'data');

    expect(count(DB::getQueryLog()))->toBeLessThan(12);
});
