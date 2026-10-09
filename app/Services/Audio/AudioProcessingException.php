<?php

declare(strict_types=1);

namespace App\Services\Audio;

use RuntimeException;

/**
 * Failure while processing an audio revision. `retryable` separates transient problems (Spaces, timeouts,
 * ffmpeg killed) from deterministic ones (source is not valid audio) that must not consume retries.
 */
final class AudioProcessingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function permanent(string $errorCode, string $message, ?\Throwable $previous = null): self
    {
        return new self($errorCode, $message, false, $previous);
    }

    public static function transient(string $errorCode, string $message, ?\Throwable $previous = null): self
    {
        return new self($errorCode, $message, true, $previous);
    }
}
