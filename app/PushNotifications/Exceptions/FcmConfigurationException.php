<?php

declare(strict_types=1);

namespace App\PushNotifications\Exceptions;

use RuntimeException;

/**
 * Thrown when the FCM provider cannot operate due to missing or invalid
 * service-account credentials / project configuration.
 *
 * Messages never contain secret material (private keys, tokens).
 */
final class FcmConfigurationException extends RuntimeException
{
    public static function missingCredentials(): self
    {
        return new self('FCM service account credentials are not configured.');
    }

    public static function invalidCredentials(string $reason): self
    {
        return new self("FCM service account credentials are invalid: {$reason}.");
    }

    public static function missingProjectId(): self
    {
        return new self('FCM project_id is not configured.');
    }
}
