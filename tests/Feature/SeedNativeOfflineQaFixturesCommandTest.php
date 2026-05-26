<?php

declare(strict_types=1);

use App\Console\Commands\SeedNativeOfflineQaFixturesCommand;
use App\Models\Sound;
use App\Models\User;
use App\Models\Vibe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

test('ixora:seed-native-offline-qa fails when user does not exist', function (): void {
    $exit = Artisan::call('ixora:seed-native-offline-qa', ['--email' => 'ghost@example.com']);

    expect($exit)->not->toBe(0);
});

test('ixora:seed-native-offline-qa creates two vibes with fixture sounds', function (): void {
    $user = User::factory()->create(['email' => 'native-qa@test.com']);

    $exit = Artisan::call('ixora:seed-native-offline-qa', [
        '--email' => 'native-qa@test.com',
    ]);

    expect($exit)->toBe(0)
        ->and(Vibe::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and(Sound::query()->where('category', '__qa_native__')->count())->toBe(2);

    $primary = Vibe::query()->where('user_id', $user->id)
        ->where('name', SeedNativeOfflineQaFixturesCommand::PRIMARY_VIBE_NAME)
        ->firstOrFail();

    expect($primary->sounds()->count())->toBe(2);

    $alternate = Vibe::query()->where('user_id', $user->id)
        ->where('name', SeedNativeOfflineQaFixturesCommand::ALTERNATE_VIBE_NAME)
        ->firstOrFail();

    expect($alternate->sounds()->count())->toBe(1);

    foreach (Sound::query()->where('category', '__qa_native__')->get() as $sound) {
        expect($sound->file_url)->toStartWith('https://');
    }
});
