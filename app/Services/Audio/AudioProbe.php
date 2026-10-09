<?php

declare(strict_types=1);

namespace App\Services\Audio;

final readonly class AudioProbe
{
    public function __construct(
        public int $audioStreams,
        public int $otherStreams,
        public ?string $codec,
        public ?int $durationMs,
        public ?int $channels,
        public ?int $sampleRate,
        public ?int $bitRate,
        public ?string $formatName,
    ) {}
}
