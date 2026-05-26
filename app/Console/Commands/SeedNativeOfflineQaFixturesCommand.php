<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Sound;
use App\Models\User;
use App\Models\Vibe;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Idempotent fixtures for native WebdriverIO offline QA (front_vibes).
 *
 * Creates two user-owned vibes with absolute HTTPS audio URLs so the mobile
 * client can download and play offline without relying on production Spaces keys.
 */
final class SeedNativeOfflineQaFixturesCommand extends Command
{
    public const PRIMARY_VIBE_NAME = '__Ixora Native QA Offline Primary';

    public const ALTERNATE_VIBE_NAME = '__Ixora Native QA Offline Alternate';

    private const FIXTURE_SOUND_A = '__Ixora Native QA Fixture Sound A';

    private const FIXTURE_SOUND_B = '__Ixora Native QA Fixture Sound B';

    private const DEFAULT_SOUND_URL_A = 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3';

    private const DEFAULT_SOUND_URL_B = 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-2.mp3';

    protected $signature = 'ixora:seed-native-offline-qa
                            {--email= : Email of an existing Laravel user (sign in once via the app so /api/auth/sync creates the row)}
                            {--force : Re-apply even in production (still requires confirmation in production)}';

    protected $description = 'Seed two downloadable vibes with HTTPS MP3 layers for native offline MiniPlayer QA.';

    public function handle(): int
    {
        $email = trim((string) ($this->option('email') ?: env('NATIVE_QA_FIXTURE_USER_EMAIL')));
        if ($email === '') {
            $this->error('Missing user email. Pass --email= or set NATIVE_QA_FIXTURE_USER_EMAIL in .env.');
            $this->line('Example: php artisan ixora:seed-native-offline-qa --email=you@example.com');

            return self::FAILURE;
        }

        $email = Str::lower($email);

        if ($this->laravel->environment('production') && ! $this->laravel->runningUnitTests()) {
            if (! $this->option('force')) {
                $this->error('Refusing to run in production without --force.');

                return self::FAILURE;
            }
            if (! $this->confirm('You are about to modify production data for QA fixtures. Continue?', false)) {
                $this->warn('Aborted.');

                return self::FAILURE;
            }
        }

        $user = User::query()->where('email', $email)->first();
        if ($user === null) {
            $this->error("No Laravel user with email [{$email}].");
            $this->line('Sign in once on the mobile app (or call POST /api/auth/sync with a valid Firebase ID token) so the API creates this user, then re-run.');

            return self::FAILURE;
        }

        $soundAUrl = trim((string) env('NATIVE_QA_FIXTURE_SOUND_URL_A', self::DEFAULT_SOUND_URL_A));
        $soundBUrl = trim((string) env('NATIVE_QA_FIXTURE_SOUND_URL_B', self::DEFAULT_SOUND_URL_B));

        if ($soundAUrl === '' || $soundBUrl === '') {
            $this->error('NATIVE_QA_FIXTURE_SOUND_URL_A / _B must not be empty when set.');

            return self::FAILURE;
        }

        foreach ([$soundAUrl, $soundBUrl] as $rawUrl) {
            if (! Str::startsWith($rawUrl, ['https://', 'http://'])) {
                $this->error("Fixture sound URL must be absolute (http/https): {$rawUrl}");

                return self::FAILURE;
            }
            if (Str::startsWith($rawUrl, 'http://') && ! $this->laravel->environment(['local', 'testing'])) {
                $this->error('Only https:// URLs are allowed outside local/testing (got http).');

                return self::FAILURE;
            }
        }

        $thumb = 'https://images.unsplash.com/photo-1493225457124-a3eb161ffa5f?w=400&h=400&fit=crop';

        $soundA = Sound::query()->updateOrCreate(
            ['name' => self::FIXTURE_SOUND_A],
            [
                'file_url' => $soundAUrl,
                'thumbnail_url' => $thumb,
                'category' => '__qa_native__',
                'duration' => null,
                'tags' => [],
                'is_active' => true,
            ],
        );

        $soundB = Sound::query()->updateOrCreate(
            ['name' => self::FIXTURE_SOUND_B],
            [
                'file_url' => $soundBUrl,
                'thumbnail_url' => $thumb,
                'category' => '__qa_native__',
                'duration' => null,
                'tags' => [],
                'is_active' => true,
            ],
        );

        $pivotBase = [
            'volume' => 80,
            'loop' => true,
            'play_mode' => 'loop',
            'repeat_interval_seconds' => null,
            'start_offset_seconds' => null,
            'play_duration_seconds' => null,
            'fade_in_seconds' => null,
            'fade_out_seconds' => null,
        ];

        $primary = Vibe::query()->updateOrCreate(
            ['user_id' => $user->id, 'name' => self::PRIMARY_VIBE_NAME],
            [
                'description' => 'Seeded for native Android offline QA (two layers).',
                'thumbnail_url' => null,
                'card_image_url' => null,
                'player_background_url' => null,
                'artwork_url' => null,
                'is_active' => true,
            ],
        );

        $primary->sounds()->sync([
            $soundA->id => [...$pivotBase, 'sort_order' => 0],
            $soundB->id => [...$pivotBase, 'sort_order' => 1],
        ]);

        $alternate = Vibe::query()->updateOrCreate(
            ['user_id' => $user->id, 'name' => self::ALTERNATE_VIBE_NAME],
            [
                'description' => 'Seeded for native Android offline QA (second vibe / switch tests).',
                'thumbnail_url' => null,
                'card_image_url' => null,
                'player_background_url' => null,
                'artwork_url' => null,
                'is_active' => true,
            ],
        );

        $alternate->sounds()->sync([
            $soundB->id => [...$pivotBase, 'sort_order' => 0],
        ]);

        $this->info("Seeded native offline QA fixtures for user [{$email}] (id={$user->id}).");
        $this->line('  • '.self::PRIMARY_VIBE_NAME." (id={$primary->id}) — 2 layers");
        $this->line('  • '.self::ALTERNATE_VIBE_NAME." (id={$alternate->id}) — 1 layer");
        $this->line('Use the same email in front_vibes E2E_USER_EMAIL when running WDIO offline QA.');

        return self::SUCCESS;
    }
}
