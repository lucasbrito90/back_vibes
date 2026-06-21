<?php

declare(strict_types=1);

namespace App\SmartHome\Adapters;

use App\Models\ProviderConnection;
use App\SmartHome\Contracts\ProviderAdapter;
use App\SmartHome\DeviceStatus;
use App\SmartHome\DTOs\ActionResult;
use App\SmartHome\DTOs\ConnectionHealth;
use App\SmartHome\DTOs\DeviceStatusResult;
use App\SmartHome\DTOs\ProviderDevice;
use App\SmartHome\Exceptions\ProviderConnectionException;
use App\SmartHome\Exceptions\UnsupportedSmartHomeActionException;
use App\SmartHome\ProviderType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Home Assistant provider adapter (ADR-013).
 *
 * Talks to a user's Home Assistant instance over its REST API using a
 * long-lived access token. The token is decrypted from the connection only at
 * call time and sent as a Bearer header — it is NEVER logged or returned in any
 * DTO.
 */
final class HomeAssistantAdapter implements ProviderAdapter
{
    /**
     * Actionable HA domains imported in MVP. Read-only domains (e.g. sensor,
     * binary_sensor) are intentionally excluded — they cannot be turned on/off.
     */
    private const ACTIONABLE_DOMAINS = ['light', 'switch', 'media_player', 'fan'];

    /**
     * IXORA action type → HA service name. The HA domain is derived from the
     * entity_id, producing service calls like `light.turn_on`.
     */
    private const ACTION_SERVICE_MAP = [
        'turn_on' => 'turn_on',
        'turn_off' => 'turn_off',
        'toggle' => 'toggle',
    ];

    public function listDevices(ProviderConnection $connection): array
    {
        try {
            $response = $this->client($connection)->get($this->baseUrl($connection).'/api/states');
        } catch (ConnectionException) {
            throw ProviderConnectionException::unreachable($this->providerSlug());
        }

        if (! $response->successful()) {
            throw ProviderConnectionException::badStatus($this->providerSlug(), $response->status());
        }

        $devices = [];

        foreach ((array) $response->json() as $state) {
            if (! is_array($state)) {
                continue;
            }

            $entityId = $state['entity_id'] ?? null;

            if (! is_string($entityId) || ! str_contains($entityId, '.')) {
                continue;
            }

            $domain = $this->domainFor($entityId);

            if (! in_array($domain, self::ACTIONABLE_DOMAINS, true)) {
                continue;
            }

            $devices[] = $this->mapDevice($entityId, $domain, $state);
        }

        return $devices;
    }

    public function readStatus(ProviderConnection $connection, string $deviceId): DeviceStatusResult
    {
        try {
            $response = $this->client($connection)->get($this->baseUrl($connection)."/api/states/{$deviceId}");
        } catch (ConnectionException) {
            return $this->unknownStatus($deviceId);
        }

        if (! $response->successful()) {
            return $this->unknownStatus($deviceId);
        }

        $data = (array) $response->json();
        $rawState = isset($data['state']) ? (string) $data['state'] : null;

        return new DeviceStatusResult(
            provider_device_id: isset($data['entity_id']) ? (string) $data['entity_id'] : $deviceId,
            status: $this->mapStatus($rawState),
            raw_state: $rawState,
            attributes: isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : [],
            last_changed: isset($data['last_changed']) ? (string) $data['last_changed'] : null,
        );
    }

    public function executeAction(
        ProviderConnection $connection,
        string $deviceId,
        string $action,
        array $parameters = []
    ): ActionResult {
        $service = self::ACTION_SERVICE_MAP[$action] ?? null;

        if ($service === null) {
            throw UnsupportedSmartHomeActionException::forAction($action);
        }

        $domain = $this->domainFor($deviceId);
        $payload = array_merge(['entity_id' => $deviceId], $parameters);

        try {
            $response = $this->client($connection)
                ->post($this->baseUrl($connection)."/api/services/{$domain}/{$service}", $payload);
        } catch (ConnectionException) {
            return new ActionResult(
                success: false,
                status_code: null,
                response: null,
                error_message: 'Provider connection failed.',
            );
        }

        $body = $response->json();

        return new ActionResult(
            success: $response->successful(),
            status_code: $response->status(),
            response: is_array($body) ? $body : null,
            error_message: $response->successful() ? null : 'Provider returned status '.$response->status().'.',
        );
    }

    public function testConnection(ProviderConnection $connection): ConnectionHealth
    {
        $start = microtime(true);

        try {
            $response = $this->client($connection)->get($this->baseUrl($connection).'/api/');
        } catch (ConnectionException) {
            return new ConnectionHealth(
                reachable: false,
                status_code: null,
                latency_ms: $this->elapsedMs($start),
                error_message: 'Provider connection failed.',
            );
        }

        if ($response->successful()) {
            return new ConnectionHealth(
                reachable: true,
                status_code: $response->status(),
                latency_ms: $this->elapsedMs($start),
                error_message: null,
            );
        }

        return new ConnectionHealth(
            reachable: false,
            status_code: $response->status(),
            latency_ms: $this->elapsedMs($start),
            error_message: 'Provider returned status '.$response->status().'.',
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Build an authenticated HTTP client. The access token is decrypted here and
     * attached as a Bearer header only — never logged.
     */
    private function client(ProviderConnection $connection): PendingRequest
    {
        $token = (string) ($connection->decryptedCredentials()['access_token'] ?? '');

        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout());
    }

    private function baseUrl(ProviderConnection $connection): string
    {
        return rtrim((string) ($connection->config['base_url'] ?? ''), '/');
    }

    private function timeout(): int
    {
        return (int) config('smart_home.providers.home_assistant.timeout', 10);
    }

    private function providerSlug(): string
    {
        return ProviderType::HomeAssistant->value;
    }

    private function domainFor(string $entityId): string
    {
        return str_contains($entityId, '.') ? explode('.', $entityId, 2)[0] : $entityId;
    }

    /**
     * Map a HA entity state into a normalised ProviderDevice.
     *
     * @param  array<string, mixed>  $state
     */
    private function mapDevice(string $entityId, string $domain, array $state): ProviderDevice
    {
        $attributes = isset($state['attributes']) && is_array($state['attributes']) ? $state['attributes'] : [];
        $rawState = isset($state['state']) ? (string) $state['state'] : null;

        $metadata = [
            'domain' => $domain,
            'raw_state' => $rawState,
        ];

        if (array_key_exists('supported_features', $attributes)) {
            $metadata['supported_features'] = $attributes['supported_features'];
        }

        if (array_key_exists('device_class', $attributes)) {
            $metadata['device_class'] = $attributes['device_class'];
        }

        $friendlyName = $attributes['friendly_name'] ?? null;

        return new ProviderDevice(
            provider_device_id: $entityId,
            name: is_string($friendlyName) && $friendlyName !== '' ? $friendlyName : $entityId,
            type: $domain,
            status: $this->mapStatus($rawState),
            metadata: $metadata,
            last_seen_at: $this->parseTimestamp($state['last_changed'] ?? null),
        );
    }

    /**
     * Normalise a HA state value to an IXORA DeviceStatus.
     *
     * - `unavailable` → Offline (provider confirmed the device is unreachable)
     * - `unknown` / missing → Unknown (status cannot be determined)
     * - any other value → Online
     */
    private function mapStatus(?string $state): DeviceStatus
    {
        return match ($state) {
            'unavailable' => DeviceStatus::Offline,
            'unknown', null, '' => DeviceStatus::Unknown,
            default => DeviceStatus::Online,
        };
    }

    private function unknownStatus(string $deviceId): DeviceStatusResult
    {
        return new DeviceStatusResult(
            provider_device_id: $deviceId,
            status: DeviceStatus::Unknown,
            raw_state: null,
            attributes: [],
            last_changed: null,
        );
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
