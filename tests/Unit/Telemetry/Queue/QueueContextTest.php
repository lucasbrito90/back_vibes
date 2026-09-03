<?php

use App\Telemetry\Queue\QueueContext;

test('toLogContext() exposes only queue, connection, job_name, and attempt', function () {
    $context = new QueueContext(connection: 'database', queue: 'default', jobName: 'ExampleJob', attempt: 2);

    expect($context->toLogContext())->toBe([
        'queue' => 'default',
        'connection' => 'database',
        'job_name' => 'ExampleJob',
        'attempt' => 2,
    ]);
});
