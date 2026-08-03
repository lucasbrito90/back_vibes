<?php

declare(strict_types=1);

namespace App\Telemetry\OpenTelemetry\Logging;

use Monolog\Handler\AbstractHandler;
use Monolog\LogRecord;
use OpenTelemetry\Contrib\Logs\Monolog\Handler as OtelMonologHandler;
use Throwable;

/**
 * Swallows exceptions thrown by the wrapped OTLP Monolog handler so that
 * Collector unavailability, authentication failures, and serialization
 * errors never propagate into the Monolog pipeline.
 *
 * Reports to PHP error_log() at most once per distinct exception class per
 * process (rate-limited by a static set) — using the PHP error channel, NOT
 * the Laravel log channel, so there is no recursive invocation risk.
 *
 * bubble=false is always forced so that a record handled here is not passed
 * on to further handlers in the same HandlerGroup context.
 *
 * SECURITY: error_log messages contain only the exception class name and a
 * generic message. They never include header values, tokens, or any secret.
 */
final class FailSafeOtelHandler extends AbstractHandler
{
    /** @var array<string, true> */
    private static array $reportedClasses = [];

    public function __construct(private readonly OtelMonologHandler $inner)
    {
        parent::__construct($inner->getLevel(), bubble: false);
    }

    public function handle(LogRecord $record): bool
    {
        try {
            $this->inner->handle($record);
        } catch (Throwable $e) {
            $class = $e::class;

            if (! isset(self::$reportedClasses[$class])) {
                self::$reportedClasses[$class] = true;
                // error_log goes to stderr/syslog — never back through the otel channel.
                // Message intentionally contains only class name and message to avoid leaking secrets.
                error_log(sprintf(
                    '[ixora.telemetry] OTLP log export failure (%s): %s',
                    $class,
                    $e->getMessage(),
                ));
            }
        }

        return false;
    }

    public function isHandling(LogRecord $record): bool
    {
        return $this->inner->isHandling($record);
    }
}
