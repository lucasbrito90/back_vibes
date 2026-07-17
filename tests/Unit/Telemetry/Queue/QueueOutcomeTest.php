<?php

use App\Telemetry\Queue\QueueOutcome;

test('the bounded set matches the spec exactly', function () {
    $values = array_map(fn (QueueOutcome $case) => $case->value, QueueOutcome::cases());

    expect($values)->toBe(['success', 'failed', 'released', 'retried', 'timed_out', 'cancelled', 'unknown']);
});
