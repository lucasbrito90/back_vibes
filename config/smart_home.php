<?php

use App\SmartHome\Adapters\HomeAssistantAdapter;
use App\SmartHome\ProviderExecutionCapability;

return [

    /*
    |--------------------------------------------------------------------------
    | Smart Home Provider Adapters (ADR-032 decision B)
    |--------------------------------------------------------------------------
    |
    | Slug => FQCN implementing ProviderAdapter. Resolved via
    | ProviderAdapterRegistry::forSlug(). Add a slug here to register a new
    | provider — no edits to ProviderAdapterResolver required.
    |
    */

    'adapters' => [
        'home_assistant' => HomeAssistantAdapter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Known Providers (ADR-036 Decision 3)
    |--------------------------------------------------------------------------
    |
    | Source of truth for provider IDENTITY — every slug the platform knows
    | about, independent of whether it has a server-side ProviderAdapter.
    | `adapters` above is a SUBSET of this list, never the other way round:
    | every adapter slug belongs to a known provider, but a known provider
    | (e.g. google_home, which is device-side only — see ADR-036) does not
    | need an adapter entry. ProviderDescriptorRegistry reads from this list,
    | not from ProviderAdapterRegistry::registeredSlugs().
    |
    | This is metadata/identity only. It does NOT make a provider eligible
    | for ProviderConnection creation — StoreProviderConnectionRequest still
    | validates the `provider` field against ProviderAdapterRegistry, which
    | is unchanged by this list (server-side connection creation semantics
    | for adapter-less providers are a separate, not-yet-scoped decision).
    |
    */

    'known_providers' => [
        'home_assistant',
        'google_home',
    ],

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

    /*
    |--------------------------------------------------------------------------
    | Provider Descriptors
    |--------------------------------------------------------------------------
    |
    | Static metadata exposed via GET /api/provider-types. Describes field
    | shapes only — never credential values. Slugs must match `known_providers`
    | above (a strict superset of registered adapter slugs).
    |
    | execution_capabilities (ADR-036 Decision 2) is a closed vocabulary
    | (App\SmartHome\ProviderExecutionCapability) describing what the
    | PROVIDER can do at the execution layer — orthogonal to, and never
    | merged or cross-validated with, a DEVICE's own capabilities
    | (ADR-033's `can_*` vocabulary).
    |
    */

    'provider_descriptors' => [

        'home_assistant' => [
            'label' => 'Home Assistant',
            'config' => [
                'base_url' => [
                    'type' => 'string',
                    'format' => 'url:https',
                    'required' => true,
                ],
            ],
            'credentials' => [
                'access_token' => [
                    'type' => 'string',
                    'required' => true,
                ],
            ],
            // Home Assistant is the server-side/scheduled provider — it
            // declares the full vocabulary except automation_delegation
            // (reserved, unused in v1.6.0).
            'execution_capabilities' => [
                ProviderExecutionCapability::DeviceDiscovery->value,
                ProviderExecutionCapability::StateRead->value,
                ProviderExecutionCapability::InteractiveExecution->value,
                ProviderExecutionCapability::ServerSideExecution->value,
                ProviderExecutionCapability::ScheduledExecution->value,
            ],
        ],

        // Google Home has no server-side credential to collect (ADR-036
        // Decision 3/4 — the Home APIs are a device-side SDK, not a
        // server-reachable API). Empty config/credentials is the honest
        // shape today, not a placeholder: there is nothing to type into a
        // connection form.
        'google_home' => [
            'label' => 'Google Home',
            'config' => [],
            'credentials' => [],
            // Device-side only (ADR-036 §1-3): the Home APIs have no
            // server-reachable surface, so neither server_side_execution
            // nor scheduled_execution is declared.
            'execution_capabilities' => [
                ProviderExecutionCapability::DeviceDiscovery->value,
                ProviderExecutionCapability::StateRead->value,
                ProviderExecutionCapability::InteractiveExecution->value,
            ],
        ],

    ],

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
    | SceneActionJob runs on the "smart-home" named queue so that device
    | action execution can be monitored and throttled independently from the
    | default Laravel queue.
    |
    | The existing queue worker (DO App Platform) processes all named queues
    | via `php artisan queue:work --queue=smart-home,default` — no new infra
    | is required (see ADR-016).
    |
    | job_timeout   — seconds before the worker kills a stalled job (matches
    |                 SceneActionJob::$timeout = 30).
    | job_tries     — max attempts before moving the job to failed_jobs
    |                 (matches SceneActionJob::$tries = 3).
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
