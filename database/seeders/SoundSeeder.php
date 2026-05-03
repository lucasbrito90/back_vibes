<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SoundSeeder extends Seeder
{
    public function run(): void
    {
        $sounds = [
            // Rain and Storm Sounds
            [
                'name'          => 'Rain',
                'file_url'      => 'sounds/rain.mp3',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1534274988757-a28bf1a57c17?w=400&h=400&fit=crop',
                'category'      => 'Rain and Storm Sounds',
                'duration'      => null,
            ],
            [
                'name'          => 'Heavy Rain',
                'file_url'      => 'sounds/heavy-rain.mp3',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1501691223387-dd0500403074?w=400&h=400&fit=crop',
                'category'      => 'Rain and Storm Sounds',
                'duration'      => null,
            ],
            [
                'name'          => 'Thunder',
                'file_url'      => 'sounds/thunder.mp3',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1605727216801-e27ce1d0cc28?w=400&h=400&fit=crop',
                'category'      => 'Rain and Storm Sounds',
                'duration'      => null,
            ],
            [
                'name'          => 'Wind',
                'file_url'      => 'sounds/wind.mp3',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1504701954957-2010ec3bcec1?w=400&h=400&fit=crop',
                'category'      => 'Rain and Storm Sounds',
                'duration'      => null,
            ],

            // Forest and Nature
            [
                'name'          => 'Birds',
                'file_url'      => 'sounds/birds.mp3',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1444464666168-49d633b86797?w=400&h=400&fit=crop',
                'category'      => 'Forest and Nature',
                'duration'      => null,
            ],
            [
                'name'          => 'Fire',
                'file_url'      => 'sounds/fire.mp3',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1559827260-dc66d52bef19?w=400&h=400&fit=crop',
                'category'      => 'Forest and Nature',
                'duration'      => null,
            ],

            // Ocean and Ambient
            [
                'name'          => 'Ocean Waves',
                'file_url'      => 'sounds/ocean-waves.mp3',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1505118380757-91f5f5632de0?w=400&h=400&fit=crop',
                'category'      => 'Ocean and Ambient',
                'duration'      => null,
            ],
            [
                'name'          => 'White Noise',
                'file_url'      => 'sounds/white-noise.mp3',
                'thumbnail_url' => 'https://images.unsplash.com/photo-1518655048521-f130df041f66?w=400&h=400&fit=crop',
                'category'      => 'Ocean and Ambient',
                'duration'      => null,
            ],
        ];

        DB::table('sounds')->insert(array_map(
            fn ($s) => [...$s, 'created_at' => now()],
            $sounds,
        ));
    }
}
