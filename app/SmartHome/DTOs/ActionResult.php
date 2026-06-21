<?php

declare(strict_types=1);

namespace App\SmartHome\DTOs;

/**
 * Result of executing an action against a provider device, as returned by
 * ProviderAdapter::executeAction(). Immutable.
 *
 * `success` is true for any 2xx provider response. On transport failure
 * (timeout / connection refused) `success` is false and `status_code` is null.
 */
final readonly class ActionResult
{
    /**
     * @param  array<string, mixed>|null  $response  Decoded provider response body, if any
     */
    public function __construct(
        public bool $success,
        public ?int $status_code,
        public ?array $response,
        public ?string $error_message,
    ) {}
}
