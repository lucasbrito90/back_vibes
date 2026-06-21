<?php

declare(strict_types=1);

namespace App\SmartHome\Exceptions;

use RuntimeException;

/**
 * Thrown when a provider cannot be reached or returns an error response for an
 * operation that has no DTO failure channel (e.g. listDevices()).
 *
 * The sync layer catches this to mark a connection's devices as `unknown`.
 *
 * Never include credentials or tokens in the message.
 */
final class ProviderConnectionException extends RuntimeException
{
    public static function unreachable(string $provider): self
    {
        return new self("Provider [{$provider}] is unreachable.");
    }

    public static function badStatus(string $provider, int $statusCode): self
    {
        return new self("Provider [{$provider}] returned status {$statusCode}.");
    }
}
