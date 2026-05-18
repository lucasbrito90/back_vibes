<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CoverBundleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $rows = [
            [
                'name' => 'Rain Night',
                'description' => 'Cool blues and rain-soaked glass textures.',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1515694346937-94d85e41e6f0?w=512&h=512&fit=crop',
                'artwork_url' => 'https://images.unsplash.com/photo-1515694346937-94d85e41e6f0?w=1024&h=1024&fit=crop',
                'player_background_url' => 'https://images.unsplash.com/photo-1515694346937-94d85e41e6f0?w=1440&h=3200&fit=crop',
                'category' => 'Weather',
                'tags' => json_encode(['rain', 'night', 'blue']),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Forest Calm',
                'description' => 'Soft greens and dappled forest light.',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1448375240586-882707db888b?w=512&h=512&fit=crop',
                'artwork_url' => 'https://images.unsplash.com/photo-1448375240586-882707db888b?w=1024&h=1024&fit=crop',
                'player_background_url' => 'https://images.unsplash.com/photo-1448375240586-882707db888b?w=1440&h=3200&fit=crop',
                'category' => 'Nature',
                'tags' => json_encode(['forest', 'green', 'calm']),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Fireplace Warm',
                'description' => 'Amber glow and cozy hearth tones.',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1549880338-65ddcdfd017b?w=512&h=512&fit=crop',
                'artwork_url' => 'https://images.unsplash.com/photo-1549880338-65ddcdfd017b?w=1024&h=1024&fit=crop',
                'player_background_url' => 'https://images.unsplash.com/photo-1549880338-65ddcdfd017b?w=1440&h=3200&fit=crop',
                'category' => 'Cozy',
                'tags' => json_encode(['fire', 'warm', 'indoor']),
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('cover_bundles')->insert($rows);
    }
}
