<?php

declare(strict_types=1);

namespace App\PushNotifications\Providers;

use App\Models\PushToken;
use App\PushNotifications\Contracts\PushProvider;
use App\PushNotifications\DTOs\NotificationPayload;
use App\PushNotifications\DTOs\PushResult;
use App\PushNotifications\Exceptions\FcmAuthenticationException;
use App\PushNotifications\Exceptions\FcmConfigurationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

/**
 * Sends push notifications through the Firebase Cloud Messaging HTTP v1 API.
 *
 * Authentication:
 * - Builds a signed JWT (RS256) from the service-account private key.
 * - Exchanges it for a short-lived OAuth access token (JWT bearer grant).
 * - Caches the token and refreshes automatically when it expires.
 *
 * Privacy:
 * - The service-account private key and OAuth assertion are never logged.
 * - The raw device token is never logged — only PushToken::tokenPreview() (ADR-021).
 *
 * Boundaries (Phase 6):
 * - One token per send() call — fan-out belongs to the job layer (not yet built).
 * - No queue, no Scheduler, no Smart Home knowledge.
 *
 * References: ADR-017, ADR-021, spec.md §6.
 */
final class FcmPushProvider implements PushProvider
{
    private const PROVIDER = 'fcm';

    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const SEND_ENDPOINT = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private const JWT_BEARER_GRANT = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    private const ACCESS_TOKEN_TTL_SECONDS = 3600;

    /** Resolved service-account array (lazy). */
    private ?array $serviceAccount = null;

    /**
     * @param  array<string, mixed>|string|null  $credentials  Service-account array or JSON file path
     */
    public function __construct(
        private readonly array|string|null $credentials,
        private readonly string $projectId = '',
        private readonly string $scope = 'https://www.googleapis.com/auth/firebase.messaging',
        private readonly int $httpTimeout = 10,
        private readonly string $tokenCacheKey = 'push_notifications:fcm:oauth_token',
        private readonly int $tokenExpirySkew = 60,
    ) {}

    public function send(PushToken $token, NotificationPayload $payload): PushResult
    {
        $accessToken = $this->accessToken();

        $endpoint = sprintf(self::SEND_ENDPOINT, $this->resolveProjectId());

        try {
            $response = Http::withToken($accessToken)
                ->timeout($this->httpTimeout)
                ->acceptJson()
                ->post($endpoint, [
                    'message' => $this->buildMessage($token, $payload),
                ]);
        } catch (ConnectionException $e) {
            Log::warning('FCM send failed: transport error.', [
                'token_preview' => $token->tokenPreview(),
                'error' => $e->getMessage(),
            ]);

            return PushResult::failure(
                provider: self::PROVIDER,
                statusCode: null,
                errorCode: 'network_error',
                errorMessage: 'FCM request did not complete (connection/timeout).',
                tokenPreview: $token->tokenPreview(),
            );
        }

        if ($response->successful()) {
            $messageId = $response->json('name');

            return PushResult::success(
                provider: self::PROVIDER,
                statusCode: $response->status(),
                messageId: is_string($messageId) ? $messageId : null,
                tokenPreview: $token->tokenPreview(),
            );
        }

        return $this->failureFromResponse($token, $response->status(), (array) $response->json());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OAuth access token lifecycle
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return a valid OAuth access token, refreshing it if missing or expired.
     *
     * @throws FcmAuthenticationException
     * @throws FcmConfigurationException
     */
    private function accessToken(): string
    {
        $cached = Cache::get($this->tokenCacheKey);

        if (is_array($cached)
            && isset($cached['access_token'], $cached['expires_at'])
            && is_string($cached['access_token'])
            && now()->lt(Carbon::parse($cached['expires_at'])->subSeconds($this->tokenExpirySkew))
        ) {
            return $cached['access_token'];
        }

        return $this->refreshAccessToken();
    }

    /**
     * @throws FcmAuthenticationException
     * @throws FcmConfigurationException
     */
    private function refreshAccessToken(): string
    {
        $assertion = $this->buildSignedAssertion();

        try {
            $response = Http::asForm()
                ->timeout($this->httpTimeout)
                ->acceptJson()
                ->post(self::TOKEN_URI, [
                    'grant_type' => self::JWT_BEARER_GRANT,
                    'assertion' => $assertion,
                ]);
        } catch (ConnectionException) {
            throw FcmAuthenticationException::tokenEndpointUnreachable();
        }

        if (! $response->successful()) {
            // Body may carry {error, error_description} — do not log assertion/key.
            Log::warning('FCM OAuth token request rejected.', [
                'status' => $response->status(),
            ]);

            throw FcmAuthenticationException::tokenEndpointRejected($response->status());
        }

        $accessToken = $response->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            throw FcmAuthenticationException::malformedTokenResponse();
        }

        $expiresIn = (int) ($response->json('expires_in') ?? self::ACCESS_TOKEN_TTL_SECONDS);
        $expiresAt = now()->addSeconds($expiresIn);

        Cache::put(
            $this->tokenCacheKey,
            ['access_token' => $accessToken, 'expires_at' => $expiresAt->toIso8601String()],
            $expiresAt,
        );

        return $accessToken;
    }

    /**
     * Build and sign the JWT bearer assertion from the service account.
     *
     * @throws FcmConfigurationException
     */
    private function buildSignedAssertion(): string
    {
        $account = $this->serviceAccount();

        $clientEmail = $account['client_email'] ?? null;
        $privateKey = $account['private_key'] ?? null;
        $tokenUri = is_string($account['token_uri'] ?? null) && $account['token_uri'] !== ''
            ? $account['token_uri']
            : self::TOKEN_URI;

        if (! is_string($clientEmail) || $clientEmail === '') {
            throw FcmConfigurationException::invalidCredentials('client_email missing');
        }
        if (! is_string($privateKey) || ! str_contains($privateKey, 'PRIVATE KEY')) {
            throw FcmConfigurationException::invalidCredentials('private_key missing or not PEM');
        }

        $issuedAt = now()->getTimestamp();
        $expiresAt = $issuedAt + self::ACCESS_TOKEN_TTL_SECONDS;

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $clientEmail,
            'scope' => $this->scope,
            'aud' => $tokenUri,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ];

        $signingInput = $this->base64UrlEncode($this->jsonEncode($header))
            .'.'.$this->base64UrlEncode($this->jsonEncode($claims));

        $signature = '';
        $signed = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if ($signed !== true || $signature === '') {
            throw FcmConfigurationException::invalidCredentials('private_key could not sign the assertion');
        }

        return $signingInput.'.'.$this->base64UrlEncode($signature);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Message + response mapping
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function buildMessage(PushToken $token, NotificationPayload $payload): array
    {
        $message = [
            'token' => $token->token,
            'notification' => [
                'title' => $payload->title,
                'body' => $payload->body,
            ],
        ];

        if ($payload->data !== []) {
            $message['data'] = $payload->data;
        }

        if ($payload->androidConfig !== null) {
            $message['android'] = $payload->androidConfig;
        }

        return $message;
    }

    /**
     * Map a non-2xx FCM response to a PushResult and log safely.
     *
     * @param  array<string, mixed>  $body
     */
    private function failureFromResponse(PushToken $token, int $statusCode, array $body): PushResult
    {
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        $errorCode = $this->extractErrorCode($error);
        $errorMessage = is_string($error['message'] ?? null) ? $error['message'] : null;

        Log::warning('FCM send failed.', [
            'token_preview' => $token->tokenPreview(),
            'status' => $statusCode,
            'error_code' => $errorCode,
        ]);

        return PushResult::failure(
            provider: self::PROVIDER,
            statusCode: $statusCode,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            tokenPreview: $token->tokenPreview(),
        );
    }

    /**
     * Prefer the FCM-specific errorCode from details[]; fall back to error.status.
     *
     * @param  array<string, mixed>  $error
     */
    private function extractErrorCode(array $error): ?string
    {
        $details = is_array($error['details'] ?? null) ? $error['details'] : [];

        foreach ($details as $detail) {
            if (is_array($detail) && is_string($detail['errorCode'] ?? null) && $detail['errorCode'] !== '') {
                return $detail['errorCode'];
            }
        }

        return is_string($error['status'] ?? null) ? $error['status'] : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Credentials resolution
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     *
     * @throws FcmConfigurationException
     */
    private function serviceAccount(): array
    {
        if ($this->serviceAccount !== null) {
            return $this->serviceAccount;
        }

        $resolved = $this->resolveCredentials($this->credentials);

        return $this->serviceAccount = $resolved;
    }

    /**
     * @param  array<string, mixed>|string|null  $credentials
     * @return array<string, mixed>
     *
     * @throws FcmConfigurationException
     */
    private function resolveCredentials(array|string|null $credentials): array
    {
        if (is_array($credentials)) {
            return $credentials;
        }

        if (is_string($credentials) && $credentials !== '') {
            if (! is_file($credentials) || ! is_readable($credentials)) {
                throw FcmConfigurationException::invalidCredentials('credentials file is not readable');
            }

            $contents = (string) file_get_contents($credentials);

            try {
                $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw FcmConfigurationException::invalidCredentials('credentials file is not valid JSON');
            }

            if (! is_array($decoded) || array_is_list($decoded)) {
                throw FcmConfigurationException::invalidCredentials('credentials file is not a JSON object');
            }

            return $decoded;
        }

        throw FcmConfigurationException::missingCredentials();
    }

    /**
     * @throws FcmConfigurationException
     */
    private function resolveProjectId(): string
    {
        if ($this->projectId !== '') {
            return $this->projectId;
        }

        $fromAccount = $this->serviceAccount()['project_id'] ?? null;

        if (is_string($fromAccount) && $fromAccount !== '') {
            return $fromAccount;
        }

        throw FcmConfigurationException::missingProjectId();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Encoding helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function jsonEncode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            throw FcmConfigurationException::invalidCredentials('unable to encode JWT segment');
        }
    }
}
