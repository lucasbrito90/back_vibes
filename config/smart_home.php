<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Smart Home Providers
    |--------------------------------------------------------------------------
    |
    | Per-provider configuration for the Smart Home adapter layer. Secrets
    | (access tokens, base URLs) are NEVER stored here — they live encrypted on
    | the provider_connections table. This file only holds non-sensitive runtime
    | tuning such as request timeouts and protocol policy.
    |
    */

    'providers' => [

        'home_assistant' => [
            // Request timeout (seconds) for all Home Assistant REST calls.
            'timeout' => env('SMART_HOME_HA_TIMEOUT', 10),

            // When false, only HTTPS Home Assistant base URLs are accepted.
            // Local development against an http:// instance may set this to true.
            'allow_http' => env('SMART_HOME_ALLOW_HTTP', false),
        ],

    ],

];
