<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

/**
 * Data-only manifest for the 50 synthetic catalog sounds.
 *
 * @return list<array{
 *     name: string,
 *     category: string,
 *     tags: list<string>,
 *     duration_seconds: int,
 *     synthesis: array<string, mixed>
 * }>
 */
final class SyntheticSoundCatalog
{
    public static function entries(): array
    {
        return array_merge(
            self::tonesAndFrequencies(),
            self::noiseAndFocus(),
            self::rainAndStorm(),
            self::forestAndNature(),
            self::oceanAndAmbient(),
            self::waterAndStreams(),
            self::fireAndWarmth(),
            self::windAndAir(),
            self::nightAndAmbience(),
        );
    }

    /** @return list<array<string, mixed>> */
    private static function tonesAndFrequencies(): array
    {
        return [
            self::entry('Pure Tone 110Hz', 'Tones and Frequencies', ['tone', 'calm', 'synthetic-audio'], 35, [
                'channels' => 1,
                'layers' => [['type' => 'sine', 'frequency_hz' => 110.0, 'amplitude' => 0.7]],
            ]),
            self::entry('Pure Tone 220Hz', 'Tones and Frequencies', ['tone', 'calm', 'synthetic-audio'], 35, [
                'channels' => 1,
                'layers' => [['type' => 'sine', 'frequency_hz' => 220.0, 'amplitude' => 0.7]],
            ]),
            self::entry('Pure Tone 440Hz', 'Tones and Frequencies', ['tone', 'focus', 'synthetic-audio'], 35, [
                'channels' => 1,
                'layers' => [['type' => 'sine', 'frequency_hz' => 440.0, 'amplitude' => 0.65]],
            ]),
            self::entry('Deep Drone 60Hz', 'Tones and Frequencies', ['tone', 'deep', 'synthetic-audio'], 40, [
                'channels' => 1,
                'layers' => [['type' => 'sine', 'frequency_hz' => 60.0, 'amplitude' => 0.75, 'lfo_hz' => 0.12, 'lfo_depth' => 0.18]],
            ]),
            self::entry('Binaural Deep Sleep (Delta 4Hz)', 'Tones and Frequencies', ['binaural', 'sleep', 'synthetic-audio'], 38, [
                'channels' => 2,
                'layers' => [['type' => 'binaural', 'frequency_left_hz' => 150.0, 'frequency_right_hz' => 154.0, 'amplitude' => 0.5]],
            ]),
            self::entry('Binaural Meditation (Theta 6Hz)', 'Tones and Frequencies', ['binaural', 'meditation', 'synthetic-audio'], 38, [
                'channels' => 2,
                'layers' => [['type' => 'binaural', 'frequency_left_hz' => 180.0, 'frequency_right_hz' => 186.0, 'amplitude' => 0.5]],
            ]),
            self::entry('Binaural Focus (Alpha 10Hz)', 'Tones and Frequencies', ['binaural', 'focus', 'synthetic-audio'], 35, [
                'channels' => 2,
                'layers' => [['type' => 'binaural', 'frequency_left_hz' => 200.0, 'frequency_right_hz' => 210.0, 'amplitude' => 0.48]],
            ]),
            self::entry('Binaural Energy (Beta 18Hz)', 'Tones and Frequencies', ['binaural', 'energy', 'synthetic-audio'], 32, [
                'channels' => 2,
                'layers' => [['type' => 'binaural', 'frequency_left_hz' => 250.0, 'frequency_right_hz' => 268.0, 'amplitude' => 0.45]],
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function noiseAndFocus(): array
    {
        return [
            self::entry('Pink Noise', 'Noise and Focus', ['noise', 'focus', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'colored_noise', 'color' => 'pink', 'amplitude' => 0.42]],
            ]),
            self::entry('Brown Noise', 'Noise and Focus', ['noise', 'deep', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'colored_noise', 'color' => 'brown', 'amplitude' => 0.45]],
            ]),
            self::entry('Deep Brown Noise', 'Noise and Focus', ['noise', 'deep', 'sleep', 'synthetic-audio'], 42, [
                'layers' => [['type' => 'colored_noise', 'color' => 'brown', 'amplitude' => 0.5]],
                'post' => [['type' => 'lowpass', 'cutoff_hz' => 320.0]],
            ]),
            self::entry('Static Hiss', 'Noise and Focus', ['noise', 'focus', 'synthetic-audio'], 35, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 9000.0, 'highpass_hz' => 2800.0, 'color' => 'white', 'amplitude' => 0.28]],
            ]),
            self::entry('Fan Hum', 'Noise and Focus', ['ambient', 'hum', 'synthetic-audio'], 38, [
                'layers' => [
                    ['type' => 'sine', 'frequency_hz' => 100.0, 'amplitude' => 0.35],
                    ['type' => 'filtered_noise_bed', 'lowpass_hz' => 400.0, 'highpass_hz' => 60.0, 'color' => 'pink', 'amplitude' => 0.28, 'gain' => 1.0],
                ],
            ]),
            self::entry('Air Conditioner Hum', 'Noise and Focus', ['ambient', 'hum', 'synthetic-audio'], 38, [
                'layers' => [
                    ['type' => 'sine', 'frequency_hz' => 120.0, 'amplitude' => 0.32],
                    ['type' => 'filtered_noise_bed', 'lowpass_hz' => 500.0, 'highpass_hz' => 80.0, 'color' => 'pink', 'amplitude' => 0.25, 'gain' => 1.0],
                ],
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function rainAndStorm(): array
    {
        return [
            self::entry('Gentle Drizzle', 'Rain and Storm Sounds', ['rain', 'nature', 'calm', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 2800.0, 'highpass_hz' => 400.0, 'color' => 'pink', 'amplitude' => 0.32]],
                'post' => [['type' => 'noise_bursts', 'bursts_per_sec' => 6.0, 'burst_duration_ms' => 18.0, 'gain' => 0.22, 'color' => 'white']],
            ]),
            self::entry('Rain on Window', 'Rain and Storm Sounds', ['rain', 'nature', 'synthetic-audio'], 42, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 3500.0, 'highpass_hz' => 500.0, 'color' => 'pink', 'amplitude' => 0.38]],
                'post' => [['type' => 'noise_bursts', 'bursts_per_sec' => 14.0, 'burst_duration_ms' => 22.0, 'gain' => 0.35, 'color' => 'white']],
            ]),
            self::entry('Rain on Tent', 'Rain and Storm Sounds', ['rain', 'nature', 'synthetic-audio'], 42, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 1800.0, 'highpass_hz' => 350.0, 'color' => 'brown', 'amplitude' => 0.4]],
                'post' => [['type' => 'noise_bursts', 'bursts_per_sec' => 12.0, 'burst_duration_ms' => 28.0, 'gain' => 0.3, 'color' => 'pink']],
            ]),
            self::entry('Rain on Leaves', 'Rain and Storm Sounds', ['rain', 'nature', 'forest', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 3200.0, 'highpass_hz' => 600.0, 'color' => 'pink', 'amplitude' => 0.34]],
                'post' => [['type' => 'noise_bursts', 'bursts_per_sec' => 9.0, 'burst_duration_ms' => 35.0, 'gain' => 0.38, 'color' => 'white']],
            ]),
            self::entry('Distant Thunderstorm', 'Rain and Storm Sounds', ['rain', 'storm', 'nature', 'synthetic-audio'], 45, [
                'layers' => [
                    ['type' => 'sine', 'frequency_hz' => 45.0, 'amplitude' => 0.12, 'lfo_hz' => 0.05, 'lfo_depth' => 0.4],
                    ['type' => 'filtered_noise_bed', 'lowpass_hz' => 1200.0, 'highpass_hz' => 200.0, 'color' => 'brown', 'amplitude' => 0.28],
                ],
                'post' => [['type' => 'noise_bursts', 'bursts_per_sec' => 0.35, 'burst_duration_ms' => 900.0, 'gain' => 0.55, 'color' => 'brown']],
            ]),
            self::entry('Rain and Thunder Mix', 'Rain and Storm Sounds', ['rain', 'storm', 'nature', 'synthetic-audio'], 45, [
                'layers' => [
                    ['type' => 'filtered_noise_bed', 'lowpass_hz' => 2600.0, 'highpass_hz' => 450.0, 'color' => 'pink', 'amplitude' => 0.36],
                    ['type' => 'sine', 'frequency_hz' => 42.0, 'amplitude' => 0.1, 'lfo_hz' => 0.07, 'lfo_depth' => 0.35],
                ],
                'post' => [
                    ['type' => 'noise_bursts', 'bursts_per_sec' => 11.0, 'burst_duration_ms' => 24.0, 'gain' => 0.32, 'color' => 'white'],
                    ['type' => 'noise_bursts', 'bursts_per_sec' => 0.5, 'burst_duration_ms' => 700.0, 'gain' => 0.45, 'color' => 'brown'],
                ],
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function forestAndNature(): array
    {
        return [
            self::entry('Forest Morning Ambience', 'Forest and Nature', ['forest', 'nature', 'morning', 'synthetic-audio'], 42, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 2200.0, 'highpass_hz' => 300.0, 'color' => 'pink', 'amplitude' => 0.22]],
                'post' => [
                    ['type' => 'chirp', 'start_freq_hz' => 1800.0, 'end_freq_hz' => 3200.0, 'duration_ms' => 120.0, 'repeat_every_ms' => 4200.0, 'jitter_ms' => 900.0, 'gain' => 0.28],
                ],
            ]),
            self::entry('Crickets at Night', 'Forest and Nature', ['forest', 'nature', 'night', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 900.0, 'highpass_hz' => 200.0, 'color' => 'brown', 'amplitude' => 0.08]],
                'post' => [
                    ['type' => 'chirp', 'start_freq_hz' => 4200.0, 'end_freq_hz' => 5200.0, 'duration_ms' => 80.0, 'repeat_every_ms' => 650.0, 'jitter_ms' => 120.0, 'gain' => 0.32],
                ],
            ]),
            self::entry('Cicadas Buzz', 'Forest and Nature', ['forest', 'nature', 'synthetic-audio'], 38, [
                'layers' => [['type' => 'sine', 'frequency_hz' => 5200.0, 'amplitude' => 0.22, 'lfo_hz' => 18.0, 'lfo_depth' => 0.65]],
            ]),
            self::entry('Owl Calls at Night', 'Forest and Nature', ['forest', 'nature', 'night', 'synthetic-audio'], 45, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 800.0, 'highpass_hz' => 150.0, 'color' => 'brown', 'amplitude' => 0.1]],
                'post' => [
                    ['type' => 'chirp', 'start_freq_hz' => 280.0, 'end_freq_hz' => 420.0, 'duration_ms' => 280.0, 'repeat_every_ms' => 8500.0, 'jitter_ms' => 2200.0, 'gain' => 0.4],
                ],
            ]),
            self::entry('Leaves Rustling in Wind', 'Forest and Nature', ['forest', 'nature', 'wind', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 2400.0, 'highpass_hz' => 500.0, 'color' => 'pink', 'amplitude' => 0.35]],
                'post' => [['type' => 'amplitude_lfo', 'lfo_hz' => 0.25, 'depth' => 0.45]],
            ]),
            self::entry('Woodland Birdsong', 'Forest and Nature', ['forest', 'nature', 'birds', 'synthetic-audio'], 42, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 1800.0, 'highpass_hz' => 350.0, 'color' => 'pink', 'amplitude' => 0.15]],
                'post' => [
                    ['type' => 'chirp', 'start_freq_hz' => 2200.0, 'end_freq_hz' => 4800.0, 'duration_ms' => 140.0, 'repeat_every_ms' => 2800.0, 'jitter_ms' => 700.0, 'gain' => 0.3],
                    ['type' => 'chirp', 'start_freq_hz' => 1600.0, 'end_freq_hz' => 3600.0, 'duration_ms' => 100.0, 'repeat_every_ms' => 3600.0, 'jitter_ms' => 1100.0, 'gain' => 0.22],
                ],
            ]),
            self::entry('Forest Stream Flow', 'Forest and Nature', ['forest', 'nature', 'water', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 4200.0, 'highpass_hz' => 800.0, 'color' => 'pink', 'amplitude' => 0.38]],
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function oceanAndAmbient(): array
    {
        return [
            self::entry('Gentle Ocean Waves', 'Ocean and Ambient', ['ocean', 'nature', 'calm', 'synthetic-audio'], 45, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 1600.0, 'highpass_hz' => 250.0, 'color' => 'brown', 'amplitude' => 0.42]],
                'post' => [['type' => 'amplitude_lfo', 'lfo_hz' => 0.07, 'depth' => 0.55]],
            ]),
            self::entry('Deep Ocean Hum', 'Ocean and Ambient', ['ocean', 'nature', 'deep', 'synthetic-audio'], 42, [
                'layers' => [
                    ['type' => 'sine', 'frequency_hz' => 55.0, 'amplitude' => 0.2],
                    ['type' => 'filtered_noise_bed', 'lowpass_hz' => 900.0, 'highpass_hz' => 120.0, 'color' => 'brown', 'amplitude' => 0.35],
                ],
            ]),
            self::entry('Lake Shore Ripples', 'Ocean and Ambient', ['ocean', 'nature', 'calm', 'synthetic-audio'], 38, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 3000.0, 'highpass_hz' => 600.0, 'color' => 'pink', 'amplitude' => 0.3]],
                'post' => [['type' => 'amplitude_lfo', 'lfo_hz' => 0.15, 'depth' => 0.35]],
            ]),
            self::entry('Stormy Sea Waves', 'Ocean and Ambient', ['ocean', 'nature', 'storm', 'synthetic-audio'], 45, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 2200.0, 'highpass_hz' => 180.0, 'color' => 'brown', 'amplitude' => 0.48]],
                'post' => [['type' => 'amplitude_lfo', 'lfo_hz' => 0.22, 'depth' => 0.6]],
            ]),
            self::entry('Underwater Ambience', 'Ocean and Ambient', ['ocean', 'nature', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 650.0, 'highpass_hz' => 80.0, 'color' => 'brown', 'amplitude' => 0.45]],
                'post' => [['type' => 'lowpass', 'cutoff_hz' => 480.0]],
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function waterAndStreams(): array
    {
        return [
            self::entry('Babbling Brook', 'Water and Streams', ['water', 'nature', 'stream', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 4800.0, 'highpass_hz' => 900.0, 'color' => 'pink', 'amplitude' => 0.36]],
                'post' => [['type' => 'noise_bursts', 'bursts_per_sec' => 18.0, 'burst_duration_ms' => 15.0, 'gain' => 0.25, 'color' => 'white']],
            ]),
            self::entry('Waterfall Rush', 'Water and Streams', ['water', 'nature', 'synthetic-audio'], 42, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 5500.0, 'highpass_hz' => 700.0, 'color' => 'white', 'amplitude' => 0.4]],
                'post' => [['type' => 'lowpass', 'cutoff_hz' => 4200.0]],
            ]),
            self::entry('Dripping Cave Water', 'Water and Streams', ['water', 'nature', 'synthetic-audio'], 45, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 1400.0, 'highpass_hz' => 250.0, 'color' => 'brown', 'amplitude' => 0.12]],
                'post' => [['type' => 'noise_bursts', 'bursts_per_sec' => 1.2, 'burst_duration_ms' => 45.0, 'gain' => 0.45, 'color' => 'pink']],
            ]),
            self::entry('River Flow', 'Water and Streams', ['water', 'nature', 'stream', 'synthetic-audio'], 42, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 3600.0, 'highpass_hz' => 450.0, 'color' => 'pink', 'amplitude' => 0.4]],
            ]),
            self::entry('Rainforest Creek', 'Water and Streams', ['water', 'nature', 'forest', 'synthetic-audio'], 42, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 4000.0, 'highpass_hz' => 550.0, 'color' => 'pink', 'amplitude' => 0.32]],
                'post' => [
                    ['type' => 'chirp', 'start_freq_hz' => 2000.0, 'end_freq_hz' => 3800.0, 'duration_ms' => 90.0, 'repeat_every_ms' => 5200.0, 'jitter_ms' => 1500.0, 'gain' => 0.2],
                ],
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function fireAndWarmth(): array
    {
        return [
            self::entry('Campfire Crackle', 'Fire and Warmth', ['fire', 'nature', 'cozy', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 1100.0, 'highpass_hz' => 180.0, 'color' => 'brown', 'amplitude' => 0.25]],
                'post' => [['type' => 'noise_bursts', 'bursts_per_sec' => 8.0, 'burst_duration_ms' => 40.0, 'gain' => 0.42, 'color' => 'white']],
            ]),
            self::entry('Fireplace Crackle', 'Fire and Warmth', ['fire', 'cozy', 'synthetic-audio'], 42, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 1300.0, 'highpass_hz' => 200.0, 'color' => 'brown', 'amplitude' => 0.28]],
                'post' => [['type' => 'noise_bursts', 'bursts_per_sec' => 14.0, 'burst_duration_ms' => 35.0, 'gain' => 0.38, 'color' => 'pink']],
            ]),
            self::entry('Crackling Logs', 'Fire and Warmth', ['fire', 'cozy', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 900.0, 'highpass_hz' => 150.0, 'color' => 'brown', 'amplitude' => 0.22]],
                'post' => [['type' => 'noise_bursts', 'bursts_per_sec' => 4.5, 'burst_duration_ms' => 55.0, 'gain' => 0.48, 'color' => 'brown']],
            ]),
            self::entry('Candle Flicker Hum', 'Fire and Warmth', ['fire', 'cozy', 'calm', 'synthetic-audio'], 35, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 700.0, 'highpass_hz' => 120.0, 'color' => 'brown', 'amplitude' => 0.18]],
                'post' => [['type' => 'amplitude_lfo', 'lfo_hz' => 1.2, 'depth' => 0.25]],
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function windAndAir(): array
    {
        return [
            self::entry('Soft Breeze', 'Wind and Air', ['wind', 'nature', 'calm', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 2000.0, 'highpass_hz' => 350.0, 'color' => 'pink', 'amplitude' => 0.3]],
                'post' => [['type' => 'amplitude_lfo', 'lfo_hz' => 0.18, 'depth' => 0.3]],
            ]),
            self::entry('Howling Wind', 'Wind and Air', ['wind', 'nature', 'synthetic-audio'], 45, [
                'layers' => [
                    ['type' => 'sine', 'frequency_hz' => 70.0, 'amplitude' => 0.12],
                    ['type' => 'filtered_noise_bed', 'lowpass_hz' => 2400.0, 'highpass_hz' => 200.0, 'color' => 'brown', 'amplitude' => 0.42],
                ],
                'post' => [['type' => 'amplitude_lfo', 'lfo_hz' => 0.35, 'depth' => 0.55]],
            ]),
            self::entry('Mountain Wind Gusts', 'Wind and Air', ['wind', 'nature', 'synthetic-audio'], 42, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 2600.0, 'highpass_hz' => 280.0, 'color' => 'pink', 'amplitude' => 0.38]],
                'post' => [['type' => 'amplitude_lfo', 'lfo_hz' => 0.28, 'depth' => 0.65]],
            ]),
            self::entry('Desert Wind', 'Wind and Air', ['wind', 'nature', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 3800.0, 'highpass_hz' => 700.0, 'color' => 'white', 'amplitude' => 0.32]],
                'post' => [['type' => 'lowpass', 'cutoff_hz' => 3200.0]],
            ]),
            self::entry('Winter Wind Chill', 'Wind and Air', ['wind', 'nature', 'cold', 'synthetic-audio'], 42, [
                'layers' => [
                    ['type' => 'filtered_noise_bed', 'lowpass_hz' => 3000.0, 'highpass_hz' => 450.0, 'color' => 'pink', 'amplitude' => 0.34],
                ],
                'post' => [
                    ['type' => 'amplitude_lfo', 'lfo_hz' => 0.3, 'depth' => 0.4],
                    ['type' => 'chirp', 'start_freq_hz' => 1200.0, 'end_freq_hz' => 2200.0, 'duration_ms' => 60.0, 'repeat_every_ms' => 6000.0, 'jitter_ms' => 2000.0, 'gain' => 0.15],
                ],
            ]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function nightAndAmbience(): array
    {
        return [
            self::entry('Quiet Night Stillness', 'Night and Ambience', ['night', 'nature', 'calm', 'synthetic-audio'], 45, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 900.0, 'highpass_hz' => 180.0, 'color' => 'brown', 'amplitude' => 0.12]],
                'post' => [
                    ['type' => 'chirp', 'start_freq_hz' => 3500.0, 'end_freq_hz' => 4200.0, 'duration_ms' => 70.0, 'repeat_every_ms' => 12000.0, 'jitter_ms' => 4000.0, 'gain' => 0.18],
                ],
            ]),
            self::entry('Desert Night Silence', 'Night and Ambience', ['night', 'nature', 'calm', 'synthetic-audio'], 45, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 600.0, 'highpass_hz' => 100.0, 'color' => 'brown', 'amplitude' => 0.08]],
            ]),
            self::entry('Mountain Night Calm', 'Night and Ambience', ['night', 'nature', 'calm', 'synthetic-audio'], 42, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 1100.0, 'highpass_hz' => 160.0, 'color' => 'brown', 'amplitude' => 0.14]],
                'post' => [['type' => 'amplitude_lfo', 'lfo_hz' => 0.12, 'depth' => 0.22]],
            ]),
            self::entry('Snowfall Hush', 'Night and Ambience', ['night', 'nature', 'winter', 'calm', 'synthetic-audio'], 40, [
                'layers' => [['type' => 'filtered_noise_bed', 'lowpass_hz' => 2400.0, 'highpass_hz' => 600.0, 'color' => 'pink', 'amplitude' => 0.16]],
            ]),
        ];
    }

    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $synthesis
     * @return array<string, mixed>
     */
    private static function entry(
        string $name,
        string $category,
        array $tags,
        int $durationSeconds,
        array $synthesis,
    ): array {
        $synthesis['duration_seconds'] = $durationSeconds;

        return [
            'name' => $name,
            'category' => $category,
            'tags' => $tags,
            'duration_seconds' => $durationSeconds,
            'synthesis' => $synthesis,
        ];
    }
}
