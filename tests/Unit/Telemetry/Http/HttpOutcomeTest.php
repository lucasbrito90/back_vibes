<?php

use App\Telemetry\Http\HttpOutcome;

test('fromStatusCode() maps status codes to bounded outcomes', function (int $statusCode, HttpOutcome $expected) {
    expect(HttpOutcome::fromStatusCode($statusCode))->toBe($expected);
})->with([
    [200, HttpOutcome::Success],
    [201, HttpOutcome::Success],
    [204, HttpOutcome::Success],
    [301, HttpOutcome::Success],
    [400, HttpOutcome::ClientError],
    [401, HttpOutcome::ClientError],
    [404, HttpOutcome::ClientError],
    [422, HttpOutcome::ClientError],
    [500, HttpOutcome::ServerError],
    [503, HttpOutcome::ServerError],
    [99, HttpOutcome::Unknown],
    [700, HttpOutcome::Unknown],
]);

test('statusCodeClass() returns a bounded status family, never the raw status code', function (int $statusCode, string $expected) {
    expect(HttpOutcome::statusCodeClass($statusCode))->toBe($expected);
})->with([
    [100, '1xx'],
    [201, '2xx'],
    [301, '3xx'],
    [404, '4xx'],
    [503, '5xx'],
    [999, 'unknown'],
    [0, 'unknown'],
]);

test('HttpOutcome exposes only the documented bounded set of values', function () {
    $values = array_map(fn (HttpOutcome $case) => $case->value, HttpOutcome::cases());

    expect($values)->toBe(['success', 'client_error', 'server_error', 'cancelled', 'unknown']);
});
