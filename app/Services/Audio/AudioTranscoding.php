<?php

declare(strict_types=1);

namespace App\Services\Audio;

interface AudioTranscoding
{
    /**
     * @throws AudioProcessingException when the file cannot be inspected
     */
    public function probe(string $path): AudioProbe;

    /**
     * Convert $inputPath to the configured distribution profile at $outputPath.
     *
     * @throws AudioProcessingException
     */
    public function transcode(string $inputPath, string $outputPath): void;
}
