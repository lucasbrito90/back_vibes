<?php

declare(strict_types=1);

namespace App\Telemetry\Logging;

use App\Telemetry\Console\ConsoleCommandTelemetry;
use Illuminate\Log\Logger;
use Monolog\LogRecord;
use Throwable;

/**
 * Laravel log "tap" (Part 9, Phase 7B.2) that enriches existing
 * command-failure log records with safe, bounded console context — added
 * to every channel the same way TraceCorrelationLogTap and
 * HttpErrorContextLogTap already are (TelemetryServiceProvider), so no
 * channel definition needs editing.
 *
 * Deliberately narrow, mirroring App\Telemetry\Logging\HttpErrorContextLogTap:
 * - Only touches records whose `context.exception` is a Throwable — in
 *   practice, Illuminate\Foundation\Console\Kernel::handle()'s own
 *   reportException() call for an uncaught exception from a real
 *   top-level `php artisan …` invocation.
 * - Only adds context when ConsoleCommandTelemetry has a command in
 *   context — see that class's docblock §"Log correlation" for why this
 *   intentionally outlives commandFinished() and why that is safe here
 *   (at most one top-level command per process).
 * - Adds only `command`, `exit_code`, `outcome` — never arguments, option
 *   values, or secrets.
 * - Never touches `message` or `context` — only `extra`, exactly like the
 *   other Phase 7A/7B log taps (logs-philosophy.md §6).
 * - A record logged before any command has started in this process (e.g.
 *   an HTTP request log in a `php-fpm`/`octane` worker that has never run
 *   a console command) is left completely untouched — this is what keeps
 *   console context from ever appearing on an HTTP-only log record, and
 *   vice versa (Part 9's log-separation requirement).
 */
final class ConsoleErrorContextLogTap
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if (! method_exists($monolog, 'pushProcessor')) {
            return;
        }

        $monolog->pushProcessor(static function (LogRecord $record): LogRecord {
            $exception = $record->context['exception'] ?? null;

            if (! $exception instanceof Throwable) {
                return $record;
            }

            try {
                if (! app()->bound(ConsoleCommandTelemetry::class)) {
                    return $record;
                }

                $context = app(ConsoleCommandTelemetry::class)->currentContext();

                if ($context === null) {
                    return $record;
                }
            } catch (Throwable) {
                return $record;
            }

            $record->extra = array_merge($record->extra, $context->toLogContext());

            return $record;
        });
    }
}
