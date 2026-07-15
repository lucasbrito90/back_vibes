<?php

use App\Telemetry\Context\TraceContext;
use App\Telemetry\Contracts\LoggerCorrelation;
use App\Telemetry\Logging\TraceCorrelationLogTap;
use App\Telemetry\Noop\NoopLoggerCorrelation;
use Illuminate\Log\Logger;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;

function tappedTestLogger(): array
{
    $monolog = new Monolog('test');
    $handler = new TestHandler;
    $monolog->pushHandler($handler);

    $logger = new Logger($monolog, app('events'));
    (new TraceCorrelationLogTap)($logger);

    return [$logger, $handler];
}

test('every configured log channel receives the trace correlation tap', function () {
    foreach (array_keys(config('logging.channels')) as $channel) {
        expect(config("logging.channels.{$channel}.tap"))->toContain(
            TraceCorrelationLogTap::class
        );
    }
});

test('trace_id and span_id are merged into the log record extra bag when a span is active, message untouched', function () {
    app()->instance(LoggerCorrelation::class, new class implements LoggerCorrelation
    {
        public function current(): array
        {
            return ['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'span_id' => '00f067aa0ba902b7'];
        }

        public function context(): ?TraceContext
        {
            return new TraceContext('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', true);
        }

        public function traceId(): ?string
        {
            return '4bf92f3577b34da6a3ce929d0e0e4736';
        }

        public function spanId(): ?string
        {
            return '00f067aa0ba902b7';
        }
    });

    [$logger, $handler] = tappedTestLogger();

    $logger->info('Schedule Smart Home dispatch skipped: validation failed.', ['schedule_id' => 42]);

    $records = $handler->getRecords();
    expect($records)->toHaveCount(1);

    $record = $records[0];

    expect($record->message)->toBe('Schedule Smart Home dispatch skipped: validation failed.')
        ->and($record->context)->toBe(['schedule_id' => 42])
        ->and($record->extra)->toBe([
            'trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736',
            'span_id' => '00f067aa0ba902b7',
        ]);
});

test('no correlation fields are added when no span is active', function () {
    app()->instance(LoggerCorrelation::class, new NoopLoggerCorrelation);

    [$logger, $handler] = tappedTestLogger();

    $logger->warning('Unsupported action type');

    $record = $handler->getRecords()[0];

    expect($record->message)->toBe('Unsupported action type')
        ->and($record->extra)->toBe([]);
});

test('a LoggerCorrelation failure never breaks logging — the tap fails open', function () {
    app()->instance(LoggerCorrelation::class, new class implements LoggerCorrelation
    {
        public function current(): array
        {
            throw new RuntimeException('collector unreachable');
        }

        public function context(): ?TraceContext
        {
            return null;
        }

        public function traceId(): ?string
        {
            return null;
        }

        public function spanId(): ?string
        {
            return null;
        }
    });

    [$logger, $handler] = tappedTestLogger();

    $logger->error('Provider call failed after retries');

    $record = $handler->getRecords()[0];

    expect($record->message)->toBe('Provider call failed after retries');
});
