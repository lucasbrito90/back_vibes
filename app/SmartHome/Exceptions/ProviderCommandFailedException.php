<?php

declare(strict_types=1);

namespace App\SmartHome\Exceptions;

use RuntimeException;

/**
 * The provider accepted the connection but could not execute the command —
 * either a transport failure (connection refused, timeout) or a non-2xx HTTP
 * response from the provider.
 *
 * Distinct from UnsupportedSmartHomeActionException (the action cannot be
 * mapped at all — a configuration fact) and ProviderConnectionException (used
 * by list/sync paths that have no DTO channel for failures). This one is
 * specifically for the direct-command path (DEV-02) where a connected provider
 * is reachable but the individual command did not complete.
 */
final class ProviderCommandFailedException extends RuntimeException {}
