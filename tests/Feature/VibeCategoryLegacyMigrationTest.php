<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function runPresetCategoryBackfillMigration(): void
{
    $migration = require database_path('migrations/2026_09_27_100003_backfill_preset_vibe_categories_from_legacy_category.php');
    $migration->up();
}

function runPresetCategoryDropMigration(): void
{
    $migration = require database_path('migrations/2026_09_27_100004_drop_category_column_from_preset_vibes_table.php');
    $migration->up();
}

test('cat02 migrations remove preset_vibes category column after refresh', function () {
    expect(Schema::hasColumn('preset_vibes', 'category'))->toBeFalse();
});

test('backfill migration maps legacy strings to catalog pivots then drop removes column', function () {
    Schema::table('preset_vibes', function (Blueprint $table): void {
        $table->string('category', 100)->nullable();
    });

    $pWeather = DB::table('preset_vibes')->insertGetId([
        'name' => 'Rain',
        'category' => 'Weather',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $pWeatherDup = DB::table('preset_vibes')->insertGetId([
        'name' => 'Storm',
        'category' => '  weather  ',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $pNature = DB::table('preset_vibes')->insertGetId([
        'name' => 'Forest',
        'category' => 'Nature',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('preset_vibes')->insert([
        'name' => 'Empty cat',
        'category' => '   ',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runPresetCategoryBackfillMigration();

    $weatherCategoryId = DB::table('vibe_categories')->where('slug', 'weather')->value('id');
    $natureCategoryId = DB::table('vibe_categories')->where('slug', 'nature')->value('id');

    expect($weatherCategoryId)->not->toBeNull()
        ->and($natureCategoryId)->not->toBeNull()
        ->and(DB::table('preset_vibe_vibe_categories')->where('preset_vibe_id', $pWeather)->count())->toBe(1)
        ->and(DB::table('preset_vibe_vibe_categories')->where('preset_vibe_id', $pWeatherDup)->count())->toBe(1)
        ->and(
            (int) DB::table('preset_vibe_vibe_categories')
                ->where('preset_vibe_id', $pWeather)
                ->value('vibe_category_id')
        )->toBe((int) $weatherCategoryId);

    $collisionLabel = 'Sleep & Rest';
    DB::table('vibe_categories')->insert([
        'slug' => 'sleep-rest',
        'names' => json_encode(['en' => 'Existing'], JSON_THROW_ON_ERROR),
        'sort_order' => 99,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pCollision = DB::table('preset_vibes')->insertGetId([
        'name' => 'Bedtime',
        'category' => $collisionLabel,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    runPresetCategoryBackfillMigration();

    $collisionCategoryId = DB::table('preset_vibe_vibe_categories')
        ->where('preset_vibe_id', $pCollision)
        ->value('vibe_category_id');

    expect($collisionCategoryId)->toBe(
        (int) DB::table('vibe_categories')->where('slug', 'sleep-rest-2')->value('id')
    );

    runPresetCategoryDropMigration();

    expect(Schema::hasColumn('preset_vibes', 'category'))->toBeFalse();
});
