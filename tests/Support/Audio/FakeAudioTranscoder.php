<?php

declare(strict_types=1);

namespace Tests\Support\Audio;

use App\Services\Audio\AudioProbe;
use App\Services\Audio\AudioProcessingException;
use App\Services\Audio\AudioTranscoding;

/**
 * Deterministic stand-in for ffmpeg/ffprobe so the default test run never spawns a process.
 * The real binaries are exercised by tests/Feature/FfmpegAudioTranscoderTest.php (opt-in).
 */
final class FakeAudioTranscoder implements AudioTranscoding
{
    public int $sourceDurationMs = 10_000;

    public ?int $outputDurationMs = null;

    public int $sourceAudioStreams = 1;

    public string $outputCodec = 'aac';

    public ?AudioProcessingException $probeSourceError = null;

    public ?AudioProcessingException $transcodeError = null;

    /** @var callable(): void|null Runs right before the transcode returns (e.g. to simulate a concurrent publish). */
    public $onTranscode = null;

    public int $transcodeCalls = 0;

    public function probe(string $path): AudioProbe
    {
        $isOutput = str_starts_with(basename($path), 'distribution.');

        if (! $isOutput && $this->probeSourceError !== null) {
            throw $this->probeSourceError;
        }

        return new AudioProbe(
            audioStreams: $isOutput ? 1 : $this->sourceAudioStreams,
            otherStreams: 0,
            codec: $isOutput ? $this->outputCodec : 'mp3',
            durationMs: $isOutput ? ($this->outputDurationMs ?? $this->sourceDurationMs) : $this->sourceDurationMs,
            channels: 2,
            sampleRate: 44_100,
            bitRate: 128_000,
            formatName: $isOutput ? 'mov,mp4,m4a,3gp,3g2,mj2' : 'mp3',
        );
    }

    public function transcode(string $inputPath, string $outputPath): void
    {
        $this->transcodeCalls++;

        if ($this->onTranscode !== null) {
            ($this->onTranscode)();
        }

        if ($this->transcodeError !== null) {
            throw $this->transcodeError;
        }

        file_put_contents($outputPath, 'fake-distribution-bytes');
    }
}
