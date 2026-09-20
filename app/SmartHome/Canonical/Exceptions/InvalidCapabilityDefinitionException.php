<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a canonical capability, operation or constraint is constructed in
 * a shape the ADR-037 contract does not admit.
 *
 * Construction is validating by design: there is no half-valid Capability
 * object. A provider mapper that cannot produce a conforming shape must fail
 * loudly at its own boundary rather than hand malformed data to the domain.
 */
final class InvalidCapabilityDefinitionException extends InvalidArgumentException {}
