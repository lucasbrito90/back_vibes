<?php

declare(strict_types=1);

use App\Services\Firebase\FirebaseCredentialsResolver;

return [
    /*
     * ------------------------------------------------------------------------
     * Default Firebase project
     * ------------------------------------------------------------------------
     */

    'default' => env('FIREBASE_PROJECT', 'app'),

    /*
     * ------------------------------------------------------------------------
     * Firebase project configurations
     * ------------------------------------------------------------------------
     */

    'projects' => [
        'app' => [

            /*
             * ------------------------------------------------------------------------
             * Credentials / Service Account
             * ------------------------------------------------------------------------
             *
             * Resolved via FirebaseCredentialsResolver (no facades; safe during config:cache):
             *
             * 1. Discrete FIREBASE_* env vars — preferred on App Platform / CI (avoid inline JSON blobs):
             *    FIREBASE_TYPE, FIREBASE_PROJECT_ID, FIREBASE_PRIVATE_KEY, FIREBASE_CLIENT_EMAIL, etc.
             *    Multi-line PEM: use FIREBASE_PRIVATE_KEY with literal `\n` sequences — Laravel normalizes these.
             * 2. FIREBASE_SERVICE_ACCOUNT_JSON — legacy inline JSON (deprecated).
             * 3. FIREBASE_CREDENTIALS — JSON file path (local dev typical).
             * 4. GOOGLE_APPLICATION_CREDENTIALS — SDK path fallback.
             *
             * If none are usable, Firebase SDK auto-discovery applies (often fails on managed platform).
             *
             * https://firebase.google.com/docs/admin/setup#initialize_the_sdk
             *
             */

            'credentials' => FirebaseCredentialsResolver::resolve(),

            /*
             * ------------------------------------------------------------------------
             * Firebase Auth Component
             * ------------------------------------------------------------------------
             */

            'auth' => [
                'tenant_id' => env('FIREBASE_AUTH_TENANT_ID'),
            ],

            /*
             * ------------------------------------------------------------------------
             * Firestore Component
             * ------------------------------------------------------------------------
             */

            'firestore' => [

                // 'database' => env('FIREBASE_FIRESTORE_DATABASE'),
            ],

            /*
             * ------------------------------------------------------------------------
             * Firebase Realtime Database
             * ------------------------------------------------------------------------
             */

            'database' => [

                'url' => env('FIREBASE_DATABASE_URL'),

                // 'auth_variable_override' => [
                //     'uid' => 'my-service-worker'
                // ],

            ],

            /*
             * ------------------------------------------------------------------------
             * Firebase Cloud Storage
             * ------------------------------------------------------------------------
             */

            'storage' => [

                'default_bucket' => env('FIREBASE_STORAGE_DEFAULT_BUCKET'),

            ],

            /*
             * ------------------------------------------------------------------------
             * Caching
             * ------------------------------------------------------------------------
             */

            'cache_store' => env('FIREBASE_CACHE_STORE', 'file'),

            /*
             * ------------------------------------------------------------------------
             * Logging
             * ------------------------------------------------------------------------
             */

            'logging' => [
                'http_log_channel' => env('FIREBASE_HTTP_LOG_CHANNEL'),
                'http_debug_log_channel' => env('FIREBASE_HTTP_DEBUG_LOG_CHANNEL'),
            ],

            /*
             * ------------------------------------------------------------------------
             * HTTP Client Options
             * ------------------------------------------------------------------------
             */

            'http_client_options' => [

                'proxy' => env('FIREBASE_HTTP_CLIENT_PROXY'),

                'timeout' => env('FIREBASE_HTTP_CLIENT_TIMEOUT'),

                'guzzle_middlewares' => [
                    // MyInvokableMiddleware::class,
                    // [MyMiddleware::class, 'static_method'],
                ],
            ],
        ],
    ],
];
