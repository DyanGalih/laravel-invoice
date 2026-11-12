<?php

namespace Tests\Feature\Http;

use Crater\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

test('every route middleware alias is registered', function () {
    $router = app('router');
    $aliases = $router->getMiddleware();
    $groups = $router->getMiddlewareGroups();

    $referencedAliases = collect($router->getRoutes())
        ->flatMap(function ($route) use ($groups) {
            return collect($route->middleware())
                ->flatMap(fn ($middleware) => $groups[$middleware] ?? [$middleware]);
        })
        ->map(fn ($middleware) => Str::before($middleware, ':'))
        ->filter(fn ($middleware) => ! class_exists($middleware))
        ->unique();

    expect($referencedAliases->diff(array_keys($aliases))->values()->all())->toBe([]);
});

test('CORS preflight keeps the configured wildcard policy', function () {
    $response = $this->call('OPTIONS', '/api/v1/cors-check', [], [], [], [
        'HTTP_ORIGIN' => 'https://customer.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
    ]);

    $response->assertNoContent();
    expect($response->headers->get('Access-Control-Allow-Origin'))->toBe('*');
});

test('forwarded headers are ignored when no proxy is trusted', function () {
    config(['trustedproxy.proxies' => null]);

    $response = app(TrustProxies::class)->handle(
        Request::create('http://app.test/path', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
        ]),
        fn ($request) => response()->json([
            'host' => $request->getHost(),
            'scheme' => $request->getScheme(),
            'client_ip' => $request->getClientIp(),
        ])
    );

    expect($response->getData(true))->toMatchArray([
        'host' => 'app.test',
        'scheme' => 'http',
        'client_ip' => '203.0.113.10',
    ]);
});

test('forwarded headers are ignored from a peer outside the trusted proxy set', function () {
    config(['trustedproxy.proxies' => '192.0.2.10']);

    $response = app(TrustProxies::class)->handle(
        Request::create('http://app.test/path', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.20',
        ]),
        fn ($request) => response()->json([
            'host' => $request->getHost(),
            'scheme' => $request->getScheme(),
            'client_ip' => $request->getClientIp(),
        ])
    );

    expect($response->getData(true))->toMatchArray([
        'host' => 'app.test',
        'scheme' => 'http',
        'client_ip' => '203.0.113.10',
    ]);
});
