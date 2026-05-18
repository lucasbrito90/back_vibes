<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CoverBundle;
use App\Models\PresetVibe;
use App\Models\PresetVibeSound;
use App\Models\Sound;
use Illuminate\Database\Seeder;

class PresetVibeSeeder extends Seeder
{
    public function run(): void
    {
        if (Sound::query()->doesntExist()) {
            return;
        }

        $bundleId = CoverBundle::query()->orderBy('id')->value('id');

        $definitions = [
            ['name' => 'Demo Rain Focus', 'category' => 'Weather', 'sound_names' => ['Rain', 'Thunder']],
            ['name' => 'Demo Forest Walk', 'category' => 'Nature', 'sound_names' => ['Birds', 'Wind']],
            ['name' => 'Demo Ocean Calm', 'category' => 'Ambient', 'sound_names' => ['Ocean Waves']],
        ];

        foreach ($definitions as $index => $def) {
            if (PresetVibe::query()->where('name', $def['name'])->exists()) {
                continue;
            }

            $soundIds = [];
            foreach ($def['sound_names'] as $soundName) {
                $id = Sound::query()->where('name', $soundName)->value('id');
                if ($id !== null) {
                    $soundIds[] = (int) $id;
                }
            }

            $soundIds = array_values(array_unique($soundIds));
            if ($soundIds === []) {
                continue;
            }

            $preset = PresetVibe::query()->create([
                'name' => $def['name'],
                'description' => 'Demo preset seeded for local development.',
                'cover_bundle_id' => $index === 0 ? $bundleId : null,
                'category' => $def['category'],
                'tags' => ['demo', 'seed'],
                'is_active' => true,
            ]);

            foreach ($soundIds as $sortOrder => $soundId) {
                PresetVibeSound::query()->create([
                    'preset_vibe_id' => $preset->id,
                    'sound_id' => $soundId,
                    'volume' => 80,
                    'loop' => true,
                    'play_mode' => 'loop',
                    'sort_order' => $sortOrder,
                    'repeat_interval_seconds' => null,
                    'start_offset_seconds' => null,
                    'play_duration_seconds' => null,
                ]);
            }
        }
    }
}
