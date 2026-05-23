<?php

declare(strict_types=1);

/**
 * Parse CORS_ALLOWED_ORIGINS: comma-separated list, trimmed, empty entries dropped.
 *
 * @return list<string>
 */
$corsAllowedOrigins = static function (): array {
    $raw = env('CORS_ALLOWED_ORIGINS');
    if (! is_string($raw) || trim($raw) === '') {
        return [];
    }

    $out = [];

    foreach (explode(',', $raw) as $part) {
        $origin = trim($part);

        if ($origin === '') {
            continue;
        }

        // Never allow wildcard via env strings (explicit hosts only).
        if ($origin === '*') {
            continue;
        }

        $out[] = $origin;
    }

    return array_values(array_unique($out));
};

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $corsAllowedOrigins(),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => max(0, (int) env('CORS_MAX_AGE', 0)),

    /*
     * Firebase uses Authorization: Bearer (not cookies). Keep false unless a client needs cookies.
     */
    'supports_credentials' => false,

];
