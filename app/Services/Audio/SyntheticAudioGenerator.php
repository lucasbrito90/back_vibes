<?php

declare(strict_types=1);

namespace App\Services\Audio;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Generates loop-friendly WAV clips in pure PHP (PCM 16-bit, no external encoders).
 *
 * @phpstan-type MonoSamples list<float>
 * @phpstan-type StereoSamples array{left: list<float>, right: list<float>}
 */
final class SyntheticAudioGenerator
{
    public const SAMPLE_RATE = 44100;

    private const MAX_SAFE_BYTES = 20 * 1024 * 1024;

    private const DEFAULT_FADE_MS = 300;

    /**
     * @param  array{
     *     duration_seconds: int|float,
     *     channels?: 1|2,
     *     fade_ms?: int,
     *     layers?: list<array<string, mixed>>,
     *     post?: list<array<string, mixed>>
     * }  $recipe
     */
    public function generateToFile(array $recipe, string $destinationPath): void
    {
        $durationSec = (float) ($recipe['duration_seconds'] ?? 35);
        $channels = (int) ($recipe['channels'] ?? 1);
        $fadeMs = (int) ($recipe['fade_ms'] ?? self::DEFAULT_FADE_MS);
        $layers = $recipe['layers'] ?? [];
        $post = $recipe['post'] ?? [];

        if ($durationSec <= 0) {
            throw new RuntimeException('duration_seconds must be positive.');
        }

        $sampleCount = (int) round($durationSec * self::SAMPLE_RATE);

        if ($channels === 2) {
            $stereo = ['left' => array_fill(0, $sampleCount, 0.0), 'right' => array_fill(0, $sampleCount, 0.0)];
            foreach ($layers as $layer) {
                $this->mixLayerStereo($stereo, $layer, $sampleCount);
            }
            foreach ($post as $step) {
                $this->applyPostStereo($stereo, $step, $sampleCount);
            }
            $this->applyFadeStereo($stereo, $fadeMs);
            $this->normalizeStereo($stereo, 0.92);
            $this->writeWavStereo($destinationPath, $stereo);
        } else {
            $mono = array_fill(0, $sampleCount, 0.0);
            foreach ($layers as $layer) {
                $this->mixLayerMono($mono, $layer, $sampleCount);
            }
            foreach ($post as $step) {
                $this->applyPostMono($mono, $step, $sampleCount);
            }
            $this->applyFadeMono($mono, $fadeMs);
            $this->normalizeMono($mono, 0.92);
            $this->writeWavMono($destinationPath, $mono);
        }

        $bytes = filesize($destinationPath);
        if ($bytes === false) {
            throw new RuntimeException('Unable to stat generated WAV.');
        }
        if ($bytes > self::MAX_SAFE_BYTES) {
            Log::error('Synthetic WAV exceeds safe size limit', [
                'path' => $destinationPath,
                'bytes' => $bytes,
                'limit' => self::MAX_SAFE_BYTES,
            ]);
            throw new RuntimeException("Generated WAV is too large ({$bytes} bytes); reduce duration or complexity.");
        }
    }

    /** @param list<float> $buffer */
    public function sineTone(array &$buffer, float $frequencyHz, float $amplitude = 0.65): void
    {
        $count = count($buffer);
        $phase = 0.0;
        $phaseInc = 2.0 * M_PI * $frequencyHz / self::SAMPLE_RATE;
        for ($i = 0; $i < $count; $i++) {
            $buffer[$i] += sin($phase) * $amplitude;
            $phase += $phaseInc;
        }
    }

    /**
     * @param  array{left: list<float>, right: list<float>}  $stereo
     */
    public function binauralTone(array &$stereo, float $freqLeft, float $freqRight, float $amplitude = 0.55): void
    {
        $count = count($stereo['left']);
        $phaseL = 0.0;
        $phaseR = 0.0;
        $incL = 2.0 * M_PI * $freqLeft / self::SAMPLE_RATE;
        $incR = 2.0 * M_PI * $freqRight / self::SAMPLE_RATE;
        for ($i = 0; $i < $count; $i++) {
            $stereo['left'][$i] += sin($phaseL) * $amplitude;
            $stereo['right'][$i] += sin($phaseR) * $amplitude;
            $phaseL += $incL;
            $phaseR += $incR;
        }
    }

    /** @param list<float> $buffer */
    public function coloredNoise(array &$buffer, string $color, float $amplitude = 0.35): void
    {
        $noise = $this->generateColoredNoise(count($buffer), $color);
        for ($i = 0, $n = count($buffer); $i < $n; $i++) {
            $buffer[$i] += $noise[$i] * $amplitude;
        }
    }

    /** @param list<float> $buffer */
    public function filteredNoiseBed(
        array &$buffer,
        float $lowpassHz,
        float $highpassHz,
        string $color = 'pink',
        float $amplitude = 0.45,
    ): void {
        $raw = $this->generateColoredNoise(count($buffer), $color);
        $filtered = $this->bandPass($raw, $highpassHz, $lowpassHz);
        for ($i = 0, $n = count($buffer); $i < $n; $i++) {
            $buffer[$i] += $filtered[$i] * $amplitude;
        }
    }

    /** @param list<float> $buffer */
    public function amplitudeLfo(array &$buffer, float $lfoHz, float $depth): void
    {
        $count = count($buffer);
        $depth = max(0.0, min(1.0, $depth));
        for ($i = 0; $i < $count; $i++) {
            $t = $i / self::SAMPLE_RATE;
            $mod = (1.0 - $depth) + $depth * (0.5 + 0.5 * sin(2.0 * M_PI * $lfoHz * $t));
            $buffer[$i] *= $mod;
        }
    }

    /** @param list<float> $buffer */
    public function noiseBursts(
        array &$buffer,
        float $burstsPerSec,
        float $burstDurationMs,
        float $gain = 0.5,
        string $color = 'white',
    ): void {
        $count = count($buffer);
        $burstSamples = max(1, (int) round($burstDurationMs / 1000 * self::SAMPLE_RATE));
        $meanInterval = (int) max(1, round(self::SAMPLE_RATE / max(0.1, $burstsPerSec)));
        $pos = (int) round(self::SAMPLE_RATE * 0.2);
        while ($pos < $count) {
            $jitter = random_int(-(int) ($meanInterval * 0.35), (int) ($meanInterval * 0.35));
            $pos += max($burstSamples, $meanInterval + $jitter);
            if ($pos >= $count) {
                break;
            }
            $start = max(0, $pos - $burstSamples);
            for ($i = $start; $i < min($count, $pos); $i++) {
                $env = sin(M_PI * ($i - $start) / max(1, $burstSamples - 1));
                $sample = $this->whiteSample() * $env * $gain;
                if ($color !== 'white') {
                    $sample *= 0.85;
                }
                $buffer[$i] += $sample;
            }
        }
    }

    /** @param list<float> $buffer */
    public function chirp(
        array &$buffer,
        float $startFreq,
        float $endFreq,
        float $durationMs,
        float $repeatEveryMs,
        float $jitterMs = 80,
        float $gain = 0.35,
    ): void {
        $count = count($buffer);
        $chirpSamples = max(1, (int) round($durationMs / 1000 * self::SAMPLE_RATE));
        $intervalSamples = max($chirpSamples, (int) round($repeatEveryMs / 1000 * self::SAMPLE_RATE));
        $pos = random_int(0, max(0, $intervalSamples / 2));
        while ($pos < $count) {
            $this->emitChirp($buffer, $pos, $chirpSamples, $startFreq, $endFreq, $gain);
            $jitter = (int) round((random_int(-1000, 1000) / 1000) * ($jitterMs / 1000 * self::SAMPLE_RATE));
            $pos += $intervalSamples + $jitter;
        }
    }

    /**
     * @param  list<float>  $buffer
     * @param  array<string, mixed>  $layer
     */
    private function mixLayerMono(array &$buffer, array $layer, int $sampleCount): void
    {
        $type = (string) ($layer['type'] ?? '');
        $gain = (float) ($layer['gain'] ?? 1.0);
        $scratch = array_fill(0, $sampleCount, 0.0);

        match ($type) {
            'sine' => $this->sineTone($scratch, (float) $layer['frequency_hz'], (float) ($layer['amplitude'] ?? 0.65) * $gain),
            'colored_noise' => $this->coloredNoise($scratch, (string) $layer['color'], (float) ($layer['amplitude'] ?? 0.35) * $gain),
            'filtered_noise_bed' => $this->filteredNoiseBed(
                $scratch,
                (float) $layer['lowpass_hz'],
                (float) $layer['highpass_hz'],
                (string) ($layer['color'] ?? 'pink'),
                (float) ($layer['amplitude'] ?? 0.45) * $gain,
            ),
            default => throw new RuntimeException("Unknown mono layer type: {$type}"),
        };

        if (isset($layer['lfo_hz'], $layer['lfo_depth'])) {
            $this->amplitudeLfo($scratch, (float) $layer['lfo_hz'], (float) $layer['lfo_depth']);
        }

        for ($i = 0; $i < $sampleCount; $i++) {
            $buffer[$i] += $scratch[$i];
        }
    }

    /**
     * @param  array{left: list<float>, right: list<float>}  $stereo
     * @param  array<string, mixed>  $layer
     */
    private function mixLayerStereo(array &$stereo, array $layer, int $sampleCount): void
    {
        $type = (string) ($layer['type'] ?? '');
        if ($type === 'binaural') {
            $this->binauralTone(
                $stereo,
                (float) $layer['frequency_left_hz'],
                (float) $layer['frequency_right_hz'],
                (float) ($layer['amplitude'] ?? 0.55),
            );

            return;
        }

        $mono = array_fill(0, $sampleCount, 0.0);
        $this->mixLayerMono($mono, $layer, $sampleCount);
        for ($i = 0; $i < $sampleCount; $i++) {
            $stereo['left'][$i] += $mono[$i];
            $stereo['right'][$i] += $mono[$i];
        }
    }

    /** @param list<float> $buffer @param array<string, mixed> $step */
    private function applyPostMono(array &$buffer, array $step, int $sampleCount): void
    {
        $type = (string) ($step['type'] ?? '');
        match ($type) {
            'amplitude_lfo' => $this->amplitudeLfo($buffer, (float) $step['lfo_hz'], (float) $step['depth']),
            'noise_bursts' => $this->noiseBursts(
                $buffer,
                (float) $step['bursts_per_sec'],
                (float) $step['burst_duration_ms'],
                (float) ($step['gain'] ?? 0.5),
                (string) ($step['color'] ?? 'white'),
            ),
            'chirp' => $this->chirp(
                $buffer,
                (float) $step['start_freq_hz'],
                (float) $step['end_freq_hz'],
                (float) $step['duration_ms'],
                (float) $step['repeat_every_ms'],
                (float) ($step['jitter_ms'] ?? 80),
                (float) ($step['gain'] ?? 0.35),
            ),
            'lowpass' => $this->lowPassInPlace($buffer, (float) $step['cutoff_hz']),
            default => throw new RuntimeException("Unknown post step: {$type}"),
        };
    }

    /** @param array{left: list<float>, right: list<float>} $stereo @param array<string, mixed> $step */
    private function applyPostStereo(array &$stereo, array $step, int $sampleCount): void
    {
        $this->applyPostMono($stereo['left'], $step, $sampleCount);
        $this->applyPostMono($stereo['right'], $step, $sampleCount);
    }

    /** @param list<float> $buffer */
    private function applyFadeMono(array &$buffer, int $fadeMs): void
    {
        $fadeSamples = min(count($buffer) / 2, (int) round($fadeMs / 1000 * self::SAMPLE_RATE));
        if ($fadeSamples < 1) {
            return;
        }
        for ($i = 0; $i < $fadeSamples; $i++) {
            $g = $i / $fadeSamples;
            $buffer[$i] *= $g;
            $buffer[count($buffer) - 1 - $i] *= $g;
        }
    }

    /** @param array{left: list<float>, right: list<float>} $stereo */
    private function applyFadeStereo(array &$stereo, int $fadeMs): void
    {
        $this->applyFadeMono($stereo['left'], $fadeMs);
        $this->applyFadeMono($stereo['right'], $fadeMs);
    }

    /** @param list<float> $buffer */
    private function normalizeMono(array &$buffer, float $targetPeak): void
    {
        $peak = 0.0;
        foreach ($buffer as $v) {
            $peak = max($peak, abs($v));
        }
        if ($peak <= 1e-9) {
            return;
        }
        $scale = $targetPeak / $peak;
        for ($i = 0, $n = count($buffer); $i < $n; $i++) {
            $buffer[$i] *= $scale;
        }
    }

    /** @param array{left: list<float>, right: list<float>} $stereo */
    private function normalizeStereo(array &$stereo, float $targetPeak): void
    {
        $peak = 0.0;
        foreach ($stereo['left'] as $i => $_) {
            $peak = max($peak, abs($stereo['left'][$i]), abs($stereo['right'][$i]));
        }
        if ($peak <= 1e-9) {
            return;
        }
        $scale = $targetPeak / $peak;
        for ($i = 0, $n = count($stereo['left']); $i < $n; $i++) {
            $stereo['left'][$i] *= $scale;
            $stereo['right'][$i] *= $scale;
        }
    }

    /** @return list<float> */
    private function generateColoredNoise(int $count, string $color): array
    {
        $white = [];
        for ($i = 0; $i < $count; $i++) {
            $white[] = $this->whiteSample();
        }

        return match ($color) {
            'white' => $white,
            'pink' => $this->pinkFilter($white),
            'brown' => $this->brownFilter($white),
            default => throw new RuntimeException("Unknown noise color: {$color}"),
        };
    }

    /** @param list<float> $white @return list<float> */
    private function pinkFilter(array $white): array
    {
        $b0 = $b1 = $b2 = $b3 = $b4 = $b5 = $b6 = 0.0;
        $out = [];
        foreach ($white as $x) {
            $b0 = 0.99886 * $b0 + $x * 0.0555179;
            $b1 = 0.99332 * $b1 + $x * 0.0750759;
            $b2 = 0.96900 * $b2 + $x * 0.1538520;
            $b3 = 0.86650 * $b3 + $x * 0.3104856;
            $b4 = 0.55000 * $b4 + $x * 0.5329522;
            $b5 = -0.7616 * $b5 - $x * 0.0168980;
            $pink = $b0 + $b1 + $b2 + $b3 + $b4 + $b5 + $b6 + $x * 0.5362;
            $b6 = $x * 0.115926;
            $out[] = $pink * 0.11;
        }

        return $out;
    }

    /** @param list<float> $white @return list<float> */
    private function brownFilter(array $white): array
    {
        $last = 0.0;
        $out = [];
        foreach ($white as $x) {
            $last = ($last + $x * 0.02) * 0.995;
            $out[] = $last;
        }

        return $out;
    }

    /** @param list<float> $input @return list<float> */
    private function bandPass(array $input, float $highpassHz, float $lowpassHz): array
    {
        $hp = $this->highPass($input, $highpassHz);

        return $this->lowPass($hp, $lowpassHz);
    }

    /** @param list<float> $input @return list<float> */
    private function lowPass(array $input, float $cutoffHz): array
    {
        $alpha = $this->alphaForCutoff($cutoffHz);
        $out = [];
        $y = 0.0;
        foreach ($input as $x) {
            $y += $alpha * ($x - $y);
            $out[] = $y;
        }

        return $out;
    }

    /** @param list<float> $buffer */
    private function lowPassInPlace(array &$buffer, float $cutoffHz): void
    {
        $filtered = $this->lowPass($buffer, $cutoffHz);
        for ($i = 0, $n = count($buffer); $i < $n; $i++) {
            $buffer[$i] = $filtered[$i];
        }
    }

    /** @param list<float> $input @return list<float> */
    private function highPass(array $input, float $cutoffHz): array
    {
        $low = $this->lowPass($input, $cutoffHz);
        $out = [];
        for ($i = 0, $n = count($input); $i < $n; $i++) {
            $out[] = $input[$i] - $low[$i];
        }

        return $out;
    }

    private function alphaForCutoff(float $cutoffHz): float
    {
        $cutoffHz = max(20.0, min($cutoffHz, self::SAMPLE_RATE / 2.5));

        return 1.0 - exp(-2.0 * M_PI * $cutoffHz / self::SAMPLE_RATE);
    }

    private function whiteSample(): float
    {
        return random_int(-10000, 10000) / 10000;
    }

    /** @param list<float> $buffer */
    private function emitChirp(
        array &$buffer,
        int $startIndex,
        int $length,
        float $startFreq,
        float $endFreq,
        float $gain,
    ): void {
        $phase = 0.0;
        for ($i = 0; $i < $length; $i++) {
            $idx = $startIndex + $i;
            if ($idx >= count($buffer)) {
                break;
            }
            $t = $i / max(1, $length - 1);
            $freq = $startFreq + ($endFreq - $startFreq) * $t;
            $phase += 2.0 * M_PI * $freq / self::SAMPLE_RATE;
            $env = sin(M_PI * $t);
            $buffer[$idx] += sin($phase) * $env * $gain;
        }
    }

    /** @param list<float> $mono */
    private function writeWavMono(string $path, array $mono): void
    {
        $this->writeWavFromSamples($path, $mono, null, 1);
    }

    /** @param array{left: list<float>, right: list<float>} $stereo */
    private function writeWavStereo(string $path, array $stereo): void
    {
        $this->writeWavFromSamples($path, $stereo['left'], $stereo['right'], 2);
    }

    /** @param list<float> $left @param list<float>|null $right */
    private function writeWavFromSamples(string $path, array $left, ?array $right, int $channels): void
    {
        $bitsPerSample = 16;
        $byteRate = (int) (self::SAMPLE_RATE * $channels * $bitsPerSample / 8);
        $blockAlign = (int) ($channels * $bitsPerSample / 8);
        $frameCount = count($left);
        $dataSize = $frameCount * $blockAlign;
        $header = pack(
            'a4Va4a4VvvVVvv',
            'RIFF',
            36 + $dataSize,
            'WAVE',
            'fmt ',
            16,
            1,
            $channels,
            self::SAMPLE_RATE,
            $byteRate,
            $blockAlign,
            $bitsPerSample,
        );
        $dataChunk = pack('a4V', 'data', $dataSize);

        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open WAV file: {$path}");
        }

        try {
            fwrite($handle, $header.$dataChunk);
            $chunk = '';
            for ($i = 0; $i < $frameCount; $i++) {
                $chunk .= $this->floatToPcm16Bytes($left[$i]);
                if ($channels === 2 && $right !== null) {
                    $chunk .= $this->floatToPcm16Bytes($right[$i]);
                }
                if (strlen($chunk) >= 65536) {
                    fwrite($handle, $chunk);
                    $chunk = '';
                }
            }
            if ($chunk !== '') {
                fwrite($handle, $chunk);
            }
        } finally {
            fclose($handle);
        }
    }

    private function floatToPcm16Bytes(float $sample): string
    {
        $clamped = max(-1.0, min(1.0, $sample));

        return pack('v', ((int) round($clamped * 32767)) & 0xFFFF);
    }
}
