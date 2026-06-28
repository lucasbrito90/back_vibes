<?php

declare(strict_types=1);

namespace App\PushNotifications\DTOs;

/**
 * Immutable result of a single push send attempt.
 *
 * Safe to log: contains no raw device token. `success` is true only for a 2xx
 * FCM response. On transport failure (timeout / connection refused) `success`
 * is false and `statusCode` is null.
 *
 * References: ADR-017, ADR-021, spec.md §6.
 */
final readonly class PushResult
{
    public function __construct(
        public bool $success,
        public ?int $statusCode = null,
        public ?string $messageId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    public static function success(int $statusCode, ?string $messageId): self
    {
        return new self(
            success: true,
            statusCode: $statusCode,
            messageId: $messageId,
        );
    }

    public static function failure(
        ?int $statusCode,
        ?string $errorCode,
        ?string $errorMessage,
    ): self {
        return new self(
            success: false,
            statusCode: $statusCode,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }
}
