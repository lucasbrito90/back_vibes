<?php

declare(strict_types=1);

use App\SmartHome\DTOs\ActionResult;
use App\SmartHome\Exceptions\UnsupportedSmartHomeActionException;
use App\SmartHome\Services\SceneActionRetryPolicy;
use InvalidArgumentException;

test('transport failures are retriable', function () {
    $policy = new SceneActionRetryPolicy;

    $result = new ActionResult(success: false, status_code: null, response: null, error_message: 'timeout');

    expect($policy->isRetriable($result, null))->toBeTrue();
});

test('provider 5xx failures are retriable', function () {
    $policy = new SceneActionRetryPolicy;

    $result = new ActionResult(success: false, status_code: 503, response: null, error_message: 'error');

    expect($policy->isRetriable($result, null))->toBeTrue();
});

test('provider 4xx failures are not retriable', function () {
    $policy = new SceneActionRetryPolicy;

    $result = new ActionResult(success: false, status_code: 404, response: null, error_message: 'not found');

    expect($policy->isRetriable($result, null))->toBeFalse();
});

test('unsupported actions are not retriable', function () {
    $policy = new SceneActionRetryPolicy;

    expect($policy->isRetriable(null, UnsupportedSmartHomeActionException::forAction('set_color')))->toBeFalse();
});

test('generic configuration throwables are not retriable', function () {
    $policy = new SceneActionRetryPolicy;

    expect($policy->isRetriable(null, new InvalidArgumentException('unknown provider')))->toBeFalse();
});
