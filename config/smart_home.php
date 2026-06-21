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

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | SmartHomeActionJob runs on the "smart-home" named queue so that device
    | action execution can be monitored and throttled independently from the
    | default Laravel queue.
    |
    | The existing queue worker (DO App Platform) processes all named queues
    | via `php artisan queue:work --queue=smart-home,default` — no new infra
    | is required (see ADR-016).
    |
    | job_timeout   — seconds before the worker kills a stalled job (matches
    |                 SmartHomeActionJob::$timeout = 30).
    | job_tries     — max attempts before moving the job to failed_jobs
    |                 (matches SmartHomeActionJob::$tries = 3).
    | queue_name    — named queue; configure the worker with
    |                 --queue=smart-home,default to ensure both queues drain.
    |
    */

    'queue' => [
        'name' => env('SMART_HOME_QUEUE_NAME', 'smart-home'),
        'job_timeout' => (int) env('SMART_HOME_JOB_TIMEOUT', 30),
        'job_tries' => (int) env('SMART_HOME_JOB_TRIES', 3),
    ],

];
