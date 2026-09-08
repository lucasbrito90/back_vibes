<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProviderConnectionRequest;
use App\Http\Requests\SyncReportedDevicesRequest;
use App\Http\Requests\UpdateProviderConnectionRequest;
use App\Http\Resources\ProviderConnectionResource;
use App\Models\ProviderConnection;
use App\SmartHome\Exceptions\ProviderConnectionException;
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
        $connection->setEncryptedCredentials($validated['encrypted_credentials']);
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
}
