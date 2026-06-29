<?php

declare(strict_types=1);

namespace App\PushNotifications\DTOs;

/**
 * Immutable result of a single push send attempt.
 *
 * Safe to log / serialize: contains no raw device token — only `tokenPreview`
 * derived from PushToken::tokenPreview(). `success` is true only for a 2xx
 * provider response (or a dry-run NoopPushProvider). On transport failure
 * (timeout / connection refused) `success` is false and `statusCode` is null.
 *
 * Field order matches spec.md §6.
 *
 * References: ADR-017, ADR-021, spec.md §6.
 */
final readonly class PushResult
{
    public function __construct(
        public bool $success,
        public string $provider,
        public ?int $statusCode = null,
        public ?string $messageId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?string $tokenPreview = null,
    ) {}

    public static function success(
        string $provider,
        ?int $statusCode = null,
        ?string $messageId = null,
        ?string $tokenPreview = null,
    ): self {
        return new self(
            success: true,
            provider: $provider,
            statusCode: $statusCode,
            messageId: $messageId,
            tokenPreview: $tokenPreview,
        );
    }

    public static function failure(
        string $provider,
        ?int $statusCode,
        ?string $errorCode,
        ?string $errorMessage,
        ?string $tokenPreview = null,
    ): self {
        return new self(
            success: false,
            provider: $provider,
            statusCode: $statusCode,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            tokenPreview: $tokenPreview,
        );
    }
}
