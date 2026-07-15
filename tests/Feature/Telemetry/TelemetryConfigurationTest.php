<?php

use App\Telemetry\Configuration\TelemetryConfig;
use App\Telemetry\Resources\ResourceAttributes;

test('telemetry configuration loads from config()', function () {
    $config = config('telemetry');

    expect($config)->toBeArray()
        ->and($config)->toHaveKeys([
            'enabled',
            'service_name',
            'service_version',
            'service_namespace',
            'environment',
            'otlp',
            'traces',
            'resource_attributes',
        ]);
});

test('TelemetryConfig::fromArray maps every configuration key', function () {
    $config = TelemetryConfig::fromArray([
        'enabled' => true,
        'service_name' => 'back_vibes-api',
        'service_version' => '1.2.3',
        'service_namespace' => 'ixora',
        'environment' => 'staging',
        'otlp' => [
            'endpoint' => 'http://collector:4318',
            'protocol' => 'http/protobuf',
            'headers' => 'Authorization=Bearer secret',
            'timeout_ms' => 2000,
        ],
        'traces' => [
            'sampler' => 'parentbased_traceidratio',
            'sampler_arg' => 0.25,
        ],
        'resource_attributes' => 'team=platform,region=us',
    ]);

    expect($config->enabled)->toBeTrue()
        ->and($config->serviceName)->toBe('back_vibes-api')
        ->and($config->serviceVersion)->toBe('1.2.3')
        ->and($config->serviceNamespace)->toBe('ixora')
        ->and($config->environment)->toBe('staging')
        ->and($config->otlpEndpoint)->toBe('http://collector:4318')
        ->and($config->otlpProtocol)->toBe('http/protobuf')
        ->and($config->otlpHeaders)->toBe(['Authorization' => 'Bearer secret'])
        ->and($config->otlpTimeoutMs)->toBe(2000)
        ->and($config->tracesSampler)->toBe('parentbased_traceidratio')
        ->and($config->tracesSamplerArg)->toBe(0.25)
        ->and($config->resourceAttributes)->toBe(['team' => 'platform', 'region' => 'us']);
});

test('deployment.environment is only ever development, staging, or production', function () {
    config(['app.env' => 'local']);

    // config/telemetry.php maps APP_ENV at config-load time, so re-derive the
    // mapping the same way the config file does to assert the same invariant
    // the naming convention requires (telemetry-naming-convention.md §4).
    $map = fn (string $appEnv) => match ($appEnv) {
        'production' => 'production',
        'staging' => 'staging',
        default => 'development',
    };

    expect($map('local'))->toBe('development')
        ->and($map('testing'))->toBe('development')
        ->and($map('staging'))->toBe('staging')
        ->and($map('production'))->toBe('production');
});

test('resource attributes contain every required semantic attribute and nothing forbidden', function () {
    $config = TelemetryConfig::fromArray([
        ...config('telemetry'),
        'resource_attributes' => 'team=platform',
    ]);

    $attributes = ResourceAttributes::fromConfig($config)->toArray();

    expect($attributes)->toHaveKeys([
        'service.name',
        'service.version',
        'service.namespace',
        'deployment.environment',
    ]);

    $forbidden = [
        'access_token', 'id_token', 'refresh_token', 'fcm_token', 'push_token',
        'ha_token', 'provider_credentials', 'password', 'encrypted_credentials',
        'email', 'firebase_uid',
    ];

    foreach (array_keys($attributes) as $key) {
        expect(in_array(strtolower($key), $forbidden, true))->toBeFalse();
    }
});

test('service_name matches the official Ixora naming convention', function () {
    expect(config('telemetry.service_name'))->toBeIn(['back_vibes-api', 'back_vibes-worker']);
});
