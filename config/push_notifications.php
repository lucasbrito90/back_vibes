<?php

declare(strict_types=1);

use App\Services\Firebase\FirebaseCredentialsResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Active push provider
    |--------------------------------------------------------------------------
    |
    | The transport used to deliver push notifications. MVP supports `fcm` only.
    | Future providers (apns, webpush) are resolved by PushProviderResolver.
    | Unsupported values fail explicitly at resolve time (InvalidArgumentException).
    |
    */

    'provider' => env('PUSH_PROVIDER', 'fcm'),

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (FCM HTTP v1)
    |--------------------------------------------------------------------------
    |
    | Credentials are resolved through FirebaseCredentialsResolver (the same
    | resolver used by Firebase Auth) and may be a service-account array or a
    | path to a service-account JSON file (FIREBASE_CREDENTIALS). Secrets are
    | NEVER stored in this file directly.
    |
    | The FcmPushProvider authenticates with a short-lived OAuth access token
    | (JWT bearer grant), caches it, and refreshes it automatically on expiry.
    |
    */

    'fcm' => [

        // Service account credentials: array | string (file path) | null.
        'credentials' => FirebaseCredentialsResolver::resolve(),

        // Firebase project id. Falls back to the credentials' project_id when blank.
        'project_id' => env('FIREBASE_PROJECT_ID', ''),

        // OAuth scope required to send messages via FCM HTTP v1.
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',

        // HTTP request timeout (seconds) for OAuth + send calls.
        'http_timeout' => (int) env('PUSH_FCM_HTTP_TIMEOUT', 10),

        // Cache key holding the cached OAuth access token + expiry.
        'token_cache_key' => env('PUSH_FCM_TOKEN_CACHE_KEY', 'push_notifications:fcm:oauth_token'),

        // Seconds subtracted from token expiry to force early refresh (clock skew safety).
        'token_expiry_skew' => (int) env('PUSH_FCM_TOKEN_EXPIRY_SKEW', 60),

    ],

];
