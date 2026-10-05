<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportHealthRequest;
use App\Http\Requests\StoreProviderConnectionRequest;
use App\Http\Requests\SyncReportedDevicesRequest;
use App\Http\Requests\UpdateProviderConnectionRequest;
use App\Http\Resources\ProviderConnectionResource;
use App\Models\ProviderConnection;
use App\SmartHome\ConnectionStatus;
use App\SmartHome\Exceptions\ProviderConnectionException;
use App\SmartHome\ProviderDescriptorRegistry;
use App\SmartHome\ProviderExecutionCapability;
use App\SmartHome\Services\ConnectionTestService;
use App\SmartHome\Services\ProviderDeviceSyncService;
use App\SmartHome\Services\ReportedDeviceSyncService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProviderConnectionController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ProviderConnection::class);

        $connections = ProviderConnection::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return ProviderConnectionResource::collection($connections);
    }

    public function store(StoreProviderConnectionRequest $request): JsonResponse
    {
        $this->authorize('create', ProviderConnection::class);

        $validated = $request->validated();

        $connection = new ProviderConnection([
            'name' => $validated['name'],
            'provider' => $validated['provider'],
            'config' => $validated['config'],
        ]);

        $connection->user_id = $request->user()->id;
        // ADR-045 Decision 5 (PRV-02): a newly-created connection has never been tested —
        // 'pending' is the correct initial lifecycle state. DB default remains 'unknown'
        // for backward compat with existing rows; the in-memory value is set explicitly
        // here to keep the response accurate without a reload.
        $connection->status = ConnectionStatus::Pending->value;

        // ADR-036 Decision 4: a provider without server_side_execution has no
        // credential for the server to hold — a placeholder value must not be
        // invented. Encrypting an empty array would produce exactly that kind
        // of placeholder (a real, non-null ciphertext of "[]"), so it is only
        // set when the client actually sent credential data; otherwise the
        // column remains genuinely NULL (fillable already covers this).
        if ($validated['encrypted_credentials'] !== []) {
            $connection->setEncryptedCredentials($validated['encrypted_credentials']);
        }

        $connection->save();

        return (new ProviderConnectionResource($connection))->response()->setStatusCode(201);
    }

    public function show(Request $request, ProviderConnection $providerConnection): ProviderConnectionResource
    {
        $this->authorize('view', $providerConnection);

        return new ProviderConnectionResource($providerConnection);
    }

    public function update(UpdateProviderConnectionRequest $request, ProviderConnection $providerConnection): ProviderConnectionResource
    {
        $this->authorize('update', $providerConnection);

        $validated = $request->validated();

        if (isset($validated['encrypted_credentials'])) {
            $providerConnection->setEncryptedCredentials($validated['encrypted_credentials']);
            unset($validated['encrypted_credentials']);
        }

        $providerConnection->fill($validated);
        $providerConnection->save();

        return new ProviderConnectionResource($providerConnection);
    }

    public function destroy(Request $request, ProviderConnection $providerConnection): JsonResponse
    {
        $this->authorize('delete', $providerConnection);

        $providerConnection->delete();

        return response()->json(null, 204);
    }

    public function sync(Request $request, ProviderConnection $providerConnection, ProviderDeviceSyncService $syncService): JsonResponse
    {
        $this->authorize('update', $providerConnection);

        try {
            $result = $syncService->sync($providerConnection);
        } catch (ProviderConnectionException $e) {
            return response()->json([
                'message' => 'Provider is unreachable. All devices for this connection have been marked unknown.',
                'provider' => $providerConnection->provider,
            ], 502);
        }

        return response()->json(['data' => [
            'provider_connection_id' => $result->provider_connection_id,
            'synced' => $result->synced,
            'created' => $result->created,
            'updated' => $result->updated,
            'offline' => $result->offline,
            'status' => $result->status,
        ]]);
    }

    /**
     * Execute an explicit server-side connectivity test for a provider connection.
     *
     * Calls testConnection() on the registered adapter, records the attempt in
     * provider_connection_attempts, and updates the connection status and
     * last_tested_at. Never touches devices.
     *
     * Only available for providers that have server-side execution capability.
     * Device-side providers must use reportHealth() instead.
     */
    public function test(
        Request $request,
        ProviderConnection $providerConnection,
        ConnectionTestService $testService,
        ProviderDescriptorRegistry $descriptorRegistry,
    ): JsonResponse {
        $this->authorize('update', $providerConnection);

        $descriptor = $descriptorRegistry->forSlug($providerConnection->provider);
        $capabilities = array_map(fn ($c) => $c->value, $descriptor->executionCapabilities);

        if (! in_array(ProviderExecutionCapability::ServerSideExecution->value, $capabilities, true)) {
            return response()->json([
                'message' => 'Server-side testing is not available for this provider. Use the client health report endpoint.',
            ], 422);
        }

        $result = $testService->testServerSide($providerConnection);

        return response()->json(['data' => [
            'status' => $result->newStatus->value,
            'outcome' => $result->outcome,
            'latency_ms' => $result->latencyMs,
            'failure_reason' => $result->failureReason,
            'last_tested_at' => $providerConnection->fresh()->last_tested_at?->toIso8601String(),
        ]]);
    }

    /**
     * Accept and persist a client-reported device catalog (ADR-036 Decision 7 / P05).
     *
     * Unlike sync() above (server-pull via ProviderDeviceSyncService for providers
     * with server-side credentials), this endpoint receives devices discovered by
     * the mobile runtime, validated by SyncReportedDevicesRequest, and upserts
     * them via ReportedDeviceSyncService.
     *
     * Ownership is enforced by a SCOPED LOOKUP (user_id match), not
     * ProviderConnectionPolicy — this is the first client-reported/untrusted-input
     * endpoint of the domain (ADR-036 Decision 7: "reported results are untrusted
     * client input"), and it deliberately returns 404 rather than the 403 the rest
     * of this controller uses for cross-ownership, so a non-owner cannot even
     * confirm the connection id exists.
     */
    public function syncReportedDevices(
        SyncReportedDevicesRequest $request,
        ProviderConnection $providerConnection,
        ReportedDeviceSyncService $syncService,
    ): JsonResponse {
        $connection = ProviderConnection::where('id', $providerConnection->id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $result = $syncService->syncReported($connection, $request->validated('devices'));

        return response()->json(['data' => [
            'provider_connection_id' => $result->provider_connection_id,
            'synced' => $result->synced,
            'created' => $result->created,
            'updated' => $result->updated,
            'offline' => $result->offline,
            'status' => $result->status,
        ]]);
    }

    /**
     * Accept and persist a client-reported health update for a device-side provider
     * connection (ADR-036 Decision 4.2 / PRV-02).
     *
     * Unlike test(), which calls the adapter, this endpoint accepts health reported
     * by the mobile runtime. Input is untrusted: ownership is enforced by a scoped
     * lookup (user_id match), the same pattern as syncReportedDevices().
     *
     * Only available for providers WITHOUT server_side_execution. Providers that
     * have a server-side adapter must use the test endpoint.
     */
    public function reportHealth(
        ReportHealthRequest $request,
        ProviderConnection $providerConnection,
        ConnectionTestService $testService,
        ProviderDescriptorRegistry $descriptorRegistry,
    ): ProviderConnectionResource {
        // Scoped lookup: returns 404 for cross-ownership (client-reported/untrusted path).
        $connection = ProviderConnection::where('id', $providerConnection->id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $descriptor = $descriptorRegistry->forSlug($connection->provider);
        $capabilities = array_map(fn ($c) => $c->value, $descriptor->executionCapabilities);

        if (in_array(ProviderExecutionCapability::ServerSideExecution->value, $capabilities, true)) {
            abort(422, 'Health for this provider is managed server-side. Use the test endpoint.');
        }

        $validated = $request->validated();

        $testService->recordClientHealth(
            $connection,
            $validated['status'],
            $validated['failure_reason'] ?? null,
            now(),
        );

        return new ProviderConnectionResource($connection->fresh());
    }
}
