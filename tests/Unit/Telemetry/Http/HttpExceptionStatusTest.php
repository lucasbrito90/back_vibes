<?php

use App\Telemetry\Http\HttpExceptionStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('estimate() maps common framework exceptions to the bounded status code they render as', function () {
    expect(HttpExceptionStatus::estimate(new NotFoundHttpException))->toBe(404)
        ->and(HttpExceptionStatus::estimate(new MethodNotAllowedHttpException(['GET'])))->toBe(405)
        ->and(HttpExceptionStatus::estimate(new AuthenticationException))->toBe(401)
        ->and(HttpExceptionStatus::estimate(new AuthorizationException))->toBe(403);
});

test('estimate() reads ValidationException::$status (defaults to 422)', function () {
    $translator = new Translator(new ArrayLoader, 'en');
    $validator = new Validator($translator, [], ['name' => 'required']);
    $exception = new ValidationException($validator);

    expect(HttpExceptionStatus::estimate($exception))->toBe(422);
});

test('estimate() treats any unrecognised exception as a server error, matching the framework default', function () {
    expect(HttpExceptionStatus::estimate(new RuntimeException('boom')))->toBe(500)
        ->and(HttpExceptionStatus::estimate(new LogicException('boom')))->toBe(500);
});
