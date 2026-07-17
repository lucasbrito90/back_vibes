<?php

declare(strict_types=1);

namespace App\Telemetry\Console;

/**
 * Produces a stable, bounded command name for telemetry — the registered
 * Artisan command name (e.g. `queue:work`, `migrate`,
 * `schedules:dispatch-loop`) as Laravel/Symfony already resolve it, never
 * the raw argv string, arguments, or option values
 * (backend-queue-console-instrumentation.md §"Queue/command normalization").
 */
final class ConsoleCommandNormalizer
{
    /**
     * Bounded fallback for a command with no resolvable name (e.g. an
     * anonymous closure command registered without ->purpose()/name, or a
     * malformed CommandStarting/CommandFinished dispatch).
     */
    public const UNKNOWN = 'unknown';

    public function normalize(?string $command): string
    {
        $command = trim((string) $command);

        return $command === '' ? self::UNKNOWN : $command;
    }
}
