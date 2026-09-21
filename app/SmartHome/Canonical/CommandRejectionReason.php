<?php

declare(strict_types=1);

namespace App\SmartHome\Canonical;

/**
 * Why a canonical command was refused (ADR-037 §7).
 *
 * Distinct cases rather than one generic failure, because the dispatch path
 * treats them differently: a device that simply lacks the capability is a
 * legitimate "unsupported" outcome, while an out-of-range parameter is a
 * malformed command that should never have been stored. Collapsing them would
 * make the first look like a bug and the second look routine.
 */
enum CommandRejectionReason: string
{
    /** The device does not declare this capability at all. */
    case CapabilityUnavailable = 'capability_unavailable';

    /** The capability exists but is read-only, or its access forbids writing. */
    case AccessForbidsOperation = 'access_forbids_operation';

    /** The capability exists and is writable, but does not declare this operation. */
    case OperationUnsupported = 'operation_unsupported';

    /** The operation is valid; the supplied value violates the canonical constraint. */
    case ParameterOutOfRange = 'parameter_out_of_range';

    /** The parameter is the wrong type, or carries a shape the contract cannot read. */
    case ParameterMalformed = 'parameter_malformed';
}
