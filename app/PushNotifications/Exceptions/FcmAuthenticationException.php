<?php

declare(strict_types=1);

namespace App\PushNotifications\Exceptions;

use RuntimeException;

/**
 * Thrown when an OAuth access token cannot be obtained from Google's token
 * endpoint (e.g. rejected service-account assertion or transport failure).
 *
 * Messages never contain the signed assertion, private key, or access token.
 */
final class FcmAuthenticationException extends RuntimeException
{
    public static function tokenEndpointRejected(?int $statusCode): self
    {
        return new self('FCM OAuth token request was rejected'.($statusCode !== null ? " (HTTP {$statusCode})" : '').'.');
    }

    public static function tokenEndpointUnreachable(): self
    {
        return new self('FCM OAuth token endpoint is unreachable.');
    }

    public static function malformedTokenResponse(): self
    {
        return new self('FCM OAuth token response did not contain an access_token.');
    }
}
