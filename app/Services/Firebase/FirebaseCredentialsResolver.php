<?php

declare(strict_types=1);

namespace App\Services\Firebase;

use InvalidArgumentException;
use JsonException;

/**
 * Resolves Kreait / Google service account credentials for config/bootstrap WITHOUT Laravel facades.
 *
 * Priority:
 * 1. Discrete FIREBASE_* env vars (staging/prod-friendly; no inline JSON blobs).
 * 2. FIREBASE_SERVICE_ACCOUNT_JSON (legacy inline JSON — deprecated).
 * 3. FIREBASE_CREDENTIALS or GOOGLE_APPLICATION_CREDENTIALS (file path).
 */
final class FirebaseCredentialsResolver
{
    /**
     * FIREBASE_* env key → JSON field name used by Google's service-account schema.
     */
    private const DISCRETE_STRING_FIELDS = [
        'FIREBASE_TYPE' => 'type',
        'FIREBASE_PROJECT_ID' => 'project_id',
        'FIREBASE_PRIVATE_KEY_ID' => 'private_key_id',
        'FIREBASE_CLIENT_EMAIL' => 'client_email',
        'FIREBASE_CLIENT_ID' => 'client_id',
        'FIREBASE_AUTH_URI' => 'auth_uri',
        'FIREBASE_TOKEN_URI' => 'token_uri',
        'FIREBASE_AUTH_PROVIDER_X509_CERT_URL' => 'auth_provider_x509_cert_url',
        'FIREBASE_CLIENT_X509_CERT_URL' => 'client_x509_cert_url',
    ];

    private const FIREBASE_PRIVATE_KEY_ENV = 'FIREBASE_PRIVATE_KEY';

    /** @var list<string> */
    private const REQUIRED_DISCRETE_TRIPLET_KEYS = [
        'FIREBASE_PROJECT_ID',
        self::FIREBASE_PRIVATE_KEY_ENV,
        'FIREBASE_CLIENT_EMAIL',
    ];

    private const DEFAULT_AUTH_URI = 'https://accounts.google.com/o/oauth2/auth';

    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const DEFAULT_AUTH_PROVIDER_X509 = 'https://www.googleapis.com/oauth2/v1/certs';

    /**
     * @return array<string, mixed>|string|null Service account array, credentials file path, or null for SDK auto-discovery
     */
    public static function resolve(): array|string|null
    {
        $snapshot = self::captureDiscreteFirebaseEnvSnapshot();
        $discreteIntent = self::classifyDiscreteEnvIntent($snapshot);

        if ($discreteIntent === 'partial') {
            throw new InvalidArgumentException(
                'Incomplete Firebase discrete environment variables: set all of FIREBASE_PROJECT_ID, FIREBASE_CLIENT_EMAIL, and FIREBASE_PRIVATE_KEY, '.
                'or remove partial FIREBASE_* values and use FIREBASE_CREDENTIALS or FIREBASE_SERVICE_ACCOUNT_JSON instead.'
            );
        }

        if ($discreteIntent === 'complete') {
            return self::credentialsArrayFromDiscreteEnvSnapshot($snapshot);
        }

        $inline = self::normalizedEnv('FIREBASE_SERVICE_ACCOUNT_JSON');
        if ($inline !== null) {
            return self::decodeInlineServiceAccount($inline);
        }

        return self::normalizedEnv('FIREBASE_CREDENTIALS')
            ?: self::normalizedEnv('GOOGLE_APPLICATION_CREDENTIALS');
    }

    /**
     * Build Google's service-account credentials array consumed by Kreait Firebase (withServiceAccount).
     * Public for unit tests — no global env lookups.
     *
     * @param  array<string, mixed>  $firebaseEnvAliases
     *
     * @return array<string, string>
     */
    public static function credentialsArrayFromDiscreteEnvSnapshot(array $firebaseEnvAliases): array
    {
        if (self::classifyDiscreteEnvIntent($firebaseEnvAliases) !== 'complete') {
            throw new InvalidArgumentException('credentialsArrayFromDiscreteEnvSnapshot expects a complete FIREBASE_* discrete env set.');
        }

        /** @var array<string, string> $out */
        $out = [];

        foreach (self::DISCRETE_STRING_FIELDS as $envName => $jsonKey) {
            $raw = $firebaseEnvAliases[$envName] ?? null;
            if (! is_string($raw)) {
                continue;
            }
            $trimmed = trim($raw);
            if ($trimmed !== '') {
                $out[$jsonKey] = $trimmed;
            }
        }

        $privateNormalized = self::normalizePrivateKey(
            is_string($firebaseEnvAliases[self::FIREBASE_PRIVATE_KEY_ENV] ?? null)
                ? (string) $firebaseEnvAliases[self::FIREBASE_PRIVATE_KEY_ENV] : ''
        );
        $out['private_key'] = $privateNormalized;

        $out['type'] = $out['type'] ?? 'service_account';

        foreach (['project_id', 'client_email'] as $k) {
            if (($out[$k] ?? '') === '') {
                throw new InvalidArgumentException("Firebase discrete credentials: {$k} must be a non-empty string.");
            }
        }

        if ($out['private_key'] === '' || ! str_contains($out['private_key'], 'PRIVATE KEY')) {
            throw new InvalidArgumentException(
                'Firebase discrete credentials: FIREBASE_PRIVATE_KEY is missing or invalid PEM after newline normalization.'
            );
        }

        $out['auth_uri'] ??= self::DEFAULT_AUTH_URI;
        $out['token_uri'] ??= self::DEFAULT_TOKEN_URI;
        $out['auth_provider_x509_cert_url'] ??= self::DEFAULT_AUTH_PROVIDER_X509;

        ksort($out);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $env
     *
     * @return 'complete'|'none'|'partial'
     */
    public static function classifyDiscreteEnvIntent(array $env): string
    {
        $presentCount = 0;

        foreach (self::REQUIRED_DISCRETE_TRIPLET_KEYS as $requiredKey) {
            $raw = $env[$requiredKey] ?? null;

            $has = false;

            if (is_string($raw) && trim($raw) !== '') {
                $has = true;
            }

            if ($has === true) {
                $presentCount++;
            }
        }

        if ($presentCount === 3) {
            return 'complete';
        }

        if ($presentCount === 1 || $presentCount === 2) {
            return 'partial';
        }

        // presentCount === 0: discrete mode not activated by the triplet; check optional-only keys.
        foreach (self::DISCRETE_STRING_FIELDS as $optionalEnv => $_jsonKey) {
            if ($optionalEnv === 'FIREBASE_TYPE') {
                continue;
            }

            $raw = $env[$optionalEnv] ?? null;

            if ($optionalEnv !== self::FIREBASE_PRIVATE_KEY_ENV && is_string($raw) && trim($raw) !== '') {
                return 'partial';
            }
        }

        return 'none';
    }

    /**
     * @return array<string, mixed>
     */
    private static function captureDiscreteFirebaseEnvSnapshot(): array
    {
        $keys = [...array_keys(self::DISCRETE_STRING_FIELDS)];
        $keys[] = self::FIREBASE_PRIVATE_KEY_ENV;
        /** @var list<string> $keys */
        $keys = array_values(array_unique($keys));

        $out = [];

        foreach ($keys as $key) {
            $value = env($key);
            $out[$key] = $value === false ? null : $value;
        }

        return $out;
    }

    private static function normalizePrivateKey(string $raw): string
    {
        return str_replace('\\n', "\n", trim($raw));
    }

    private static function normalizedEnv(string $key): ?string
    {
        $value = env($key);

        if ($value === false || $value === null) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Decode and validate a Firebase / Google service account JSON string (e.g. from FIREBASE_SERVICE_ACCOUNT_JSON).
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function decodeInlineServiceAccount(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            error_log('Firebase admin credentials: FIREBASE_SERVICE_ACCOUNT_JSON is not valid JSON. '.$e->getMessage());

            throw new InvalidArgumentException(
                'FIREBASE_SERVICE_ACCOUNT_JSON must contain valid JSON for the Firebase service account. '.$e->getMessage(),
                previous: $e
            );
        }

        if (! is_array($data) || array_is_list($data)) {
            error_log('Firebase admin credentials: FIREBASE_SERVICE_ACCOUNT_JSON decoded to a non-object JSON value.');

            throw new InvalidArgumentException(
                'FIREBASE_SERVICE_ACCOUNT_JSON must decode to a JSON object (service account key).'
            );
        }

        $projectId = $data['project_id'] ?? null;
        if (! is_string($projectId) || $projectId === '') {
            error_log('Firebase admin credentials: FIREBASE_SERVICE_ACCOUNT_JSON is missing project_id.');

            throw new InvalidArgumentException(
                'FIREBASE_SERVICE_ACCOUNT_JSON must include a non-empty string "project_id" field (Firebase Google service account JSON).'
            );
        }

        return $data;
    }
}
