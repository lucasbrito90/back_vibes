<?php

use App\Telemetry\Http\HttpRouteNormalizer;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

test('normalize() returns the route URI template with a leading slash when a route resolved', function () {
    $request = Request::create('/api/vibes/42', 'GET');
    $route = new Route('GET', 'api/vibes/{vibe}', fn () => null);
    $request->setRouteResolver(fn () => $route);

    expect((new HttpRouteNormalizer)->normalize($request))->toBe('/api/vibes/{vibe}');
});

test('normalize() falls back to the bounded UNMATCHED value when no route resolved', function () {
    $request = Request::create('/does-not-exist', 'GET');

    expect((new HttpRouteNormalizer)->normalize($request))->toBe(HttpRouteNormalizer::UNMATCHED);
});

test('normalize() never leaks a dynamic path segment for the resolved route', function () {
    $request = Request::create('/api/vibes/999', 'GET');
    $route = new Route('GET', 'api/vibes/{vibe}', fn () => null);
    $request->setRouteResolver(fn () => $route);

    expect((new HttpRouteNormalizer)->normalize($request))->not->toContain('999');
});

test('normalize() never includes the query string', function () {
    $request = Request::create('/api/vibes/999?token=abc&user_id=1', 'GET');
    $route = new Route('GET', 'api/vibes/{vibe}', fn () => null);
    $request->setRouteResolver(fn () => $route);

    $normalized = (new HttpRouteNormalizer)->normalize($request);

    expect($normalized)->not->toContain('?')
        ->and($normalized)->not->toContain('token')
        ->and($normalized)->not->toContain('abc');
});

test('normalize() returns "/" for the root route', function () {
    $request = Request::create('/', 'GET');
    $route = new Route('GET', '/', fn () => null);
    $request->setRouteResolver(fn () => $route);

    expect((new HttpRouteNormalizer)->normalize($request))->toBe('/');
});
