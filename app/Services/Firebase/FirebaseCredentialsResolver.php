<?php

declare(strict_types=1);

namespace App\Services\Firebase;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;

/**
 * Resolves Kreait Firebase credentials from environment:
 * - FIREBASE_SERVICE_ACCOUNT_JSON (inline JSON, e.g. DigitalOcean App Platform secrets) takes precedence
 * - FIREBASE_CREDENTIALS (relative/absolute path to a JSON file) for local development
 * - GOOGLE_APPLICATION_CREDENTIALS as final string fallback (SDK convention)
 */
final class FirebaseCredentialsResolver
{
    /**
     * @return array<string, mixed>|string|null Service account array, credentials file path, or null for SDK auto-discovery
     */
    public static function resolve(): array|string|null
    {
        $inline = self::normalizedEnv('FIREBASE_SERVICE_ACCOUNT_JSON');
        if ($inline !== null) {
            return self::decodeInlineServiceAccount($inline);
        }

        $path = self::normalizedEnv('FIREBASE_CREDENTIALS')
            ?: self::normalizedEnv('GOOGLE_APPLICATION_CREDENTIALS');

        return $path;
    }

    private static function normalizedEnv(string $key): ?string
    {
        $value = env($key);

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
            Log::error('Firebase admin credentials: FIREBASE_SERVICE_ACCOUNT_JSON is not valid JSON.', [
                'exception' => $e->getMessage(),
            ]);

            throw new InvalidArgumentException(
                'FIREBASE_SERVICE_ACCOUNT_JSON must contain valid JSON for the Firebase service account. '.$e->getMessage(),
                previous: $e
            );
        }

        if (! is_array($data) || array_is_list($data)) {
            Log::error('Firebase admin credentials: FIREBASE_SERVICE_ACCOUNT_JSON decoded to a non-object JSON value.');

            throw new InvalidArgumentException(
                'FIREBASE_SERVICE_ACCOUNT_JSON must decode to a JSON object (service account key).'
            );
        }

        $projectId = $data['project_id'] ?? null;
        if (! is_string($projectId) || $projectId === '') {
            Log::error('Firebase admin credentials: FIREBASE_SERVICE_ACCOUNT_JSON is missing project_id.');

            throw new InvalidArgumentException(
                'FIREBASE_SERVICE_ACCOUNT_JSON must include a non-empty string "project_id" field (Firebase Google service account JSON).'
            );
        }

        return $data;
    }
}
