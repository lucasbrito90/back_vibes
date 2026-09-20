<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

/**
 * The outcome of validating a canonical command (ADR-037 §7).
 *
 * A result object rather than a thrown exception, because the two call sites
 * need opposite things from the same check: a FormRequest wants a message to
 * attach to a field, while the dispatch path wants to decide between skipping,
 * failing and logging. Throwing would force one of them to catch for control
 * flow.
 *
 * Messages are provider-agnostic by construction — they speak of capabilities,
 * operations and canonical units, never of a Home Assistant service or a Google
 * trait. A user reading "Brightness must be at most 100 percent" should not be
 * able to tell which ecosystem the device came from.
 */
final readonly class CommandValidationResult
{
    private function __construct(
        public bool $valid,
        public ?string $message,
        public ?CommandRejectionReason $reason,
    ) {}

    public static function valid(): self
    {
        return new self(true, null, null);
    }

    public static function reject(CommandRejectionReason $reason, string $message): self
    {
        return new self(false, $message, $reason);
    }

    public function wasRejected(): bool
    {
        return ! $this->valid;
    }
}
