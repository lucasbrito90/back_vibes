<?php

declare(strict_types=1);

use App\Console\Commands\Support\SyntheticSoundCatalog;
use App\Services\Audio\SyntheticAudioGenerator;

test('synthetic audio generator writes a valid mono wav header', function (): void {
    $generator = new SyntheticAudioGenerator;
    $path = sys_get_temp_dir().'/ixora-synthetic-test-'.uniqid('', true).'.wav';

    try {
        $generator->generateToFile([
            'duration_seconds' => 2,
            'layers' => [['type' => 'sine', 'frequency_hz' => 440.0, 'amplitude' => 0.5]],
        ], $path);
        expect(is_file($path))->toBeTrue();
        $header = file_get_contents($path, false, null, 0, 12);
        expect($header)->toBeString()->toStartWith('RIFF')->and(substr($header, 8, 4))->toBe('WAVE');
        expect(filesize($path))->toBeLessThan(20 * 1024 * 1024);
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

test('catalog manifest contains fifty entries', function (): void {
    expect(SyntheticSoundCatalog::entries())->toHaveCount(50);
});
