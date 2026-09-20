<?php

declare(strict_types=1);

namespace App\SmartHome\Exceptions;

use App\SmartHome\Canonical\CommandRejectionReason;
use RuntimeException;

/**
 * A stored command whose parameters violate the canonical contract (ADR-037 §7).
 *
 * Deliberately NOT UnsupportedSmartHomeActionException. That one means "this
 * device cannot do this" — a legitimate outcome the telemetry vocabulary
 * already records as Unsupported, and a state the user can fix by choosing a
 * different action. A value outside its constraint is a different animal: the
 * device supports the operation perfectly well, and the command is simply
 * malformed. Recording it as Unsupported would make a real defect look like a
 * routine capability mismatch on every dashboard that groups by outcome.
 *
 * Mapping to the Failure outcome needs no new telemetry case: SceneActionJob
 * already classifies any non-Unsupported throwable as Failure, so ADR-034's
 * closed vocabulary stays closed.
 */
final class InvalidCanonicalCommandException extends RuntimeException
{
    public static function because(CommandRejectionReason $reason, string $message): self
    {
        return new self(sprintf('[%s] %s', $reason->value, $message));
    }
}
