<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteDeviceCommandRequest;
use App\Http\Requests\StoreDeviceRequest;
use App\Http\Requests\UpdateDeviceRequest;
use App\Http\Resources\DeviceDetailResource;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\SmartHome\Canonical\CapabilityId;
use App\SmartHome\Canonical\Operation;
use App\SmartHome\DeviceStatus;
use App\SmartHome\Exceptions\ProviderCommandFailedException;
use App\SmartHome\Exceptions\UnsupportedSmartHomeActionException;
use App\SmartHome\Services\DeviceCommandService;
use App\SmartHome\Services\DeviceStateService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeviceController extends Controller
{
    use AuthorizesRequests;

    /**
     * The user's devices, each with its last-known functional state.
     *
     * No provider read happens here, by design (DEV-01): refreshing N devices
     * on a list request would mean N provider calls per page load, which is the
     * cost risk the card named. The list reports stored state with its
     * freshness marker, so a client can tell a current value from an aged one
     * without the backend fanning out.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Device::class);

        $devices = Device::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return DeviceResource::collection($devices);
    }

    public function store(StoreDeviceRequest $request): JsonResponse
    {
        $this->authorize('create', Device::class);

        $validated = $request->validated();

        /** @var ProviderConnection $connection */
        $connection = ProviderConnection::findOrFail($validated['provider_connection_id']);

        $device = Device::create([
            'user_id' => $request->user()->id,
            'provider_connection_id' => $connection->id,
            'provider' => $connection->provider,
            'name' => $validated['name'],
            'type' => $validated['type'] ?? null,
            'provider_device_id' => $validated['provider_device_id'],
            'status' => DeviceStatus::Unknown->value,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        return (new DeviceResource($device))->response()->setStatusCode(201);
    }

    /**
     * Device detail, including canonical functional state (DEV-01).
     *
     * Read-through: refreshes state from the provider when the stored value is
     * absent or older than the TTL, so opening a device shows what it is
     * actually doing. Bounded to one device and skipped entirely when a recent
     * read is on hand. index() deliberately does NOT do this — see its note.
     */
    public function show(
        Request $request,
        Device $device,
        DeviceStateService $stateService,
    ): DeviceDetailResource {
        $this->authorize('view', $device);

        $stateService->refreshIfStale($device);

        return new DeviceDetailResource($device);
    }

    public function update(UpdateDeviceRequest $request, Device $device): DeviceResource
    {
        $this->authorize('update', $device);

        $validated = $request->validated();

        if (isset($validated['provider_connection_id'])) {
            $connection = ProviderConnection::findOrFail($validated['provider_connection_id']);
            $validated['provider'] = $connection->provider;
        }

        $device->fill($validated);
        $device->save();

        return new DeviceResource($device);
    }

    /**
     * Execute a direct canonical capability command on a device (DEV-02).
     *
     * POST /api/devices/{device}/commands
     *
     * Validates the canonical command against the device's declared capabilities
     * before any provider call (see ExecuteDeviceCommandRequest). The
     * server-side/device-side split mirrors SceneDispatchService: providers that
     * do not declare ServerSideExecution receive a `client_execute` instruction
     * back — the backend performs no provider call for them.
     */
    public function commands(
        ExecuteDeviceCommandRequest $request,
        Device $device,
        DeviceCommandService $commandService,
    ): JsonResponse {
        $this->authorize('view', $device);

        try {
            $result = $commandService->execute(
                $device,
                CapabilityId::from((string) $request->input('capability_id')),
                Operation::from((string) $request->input('operation')),
                is_array($request->input('parameters')) ? $request->input('parameters') : [],
            );
        } catch (UnsupportedSmartHomeActionException) {
            return response()->json(
                ['message' => 'This operation is not supported by the device provider.'],
                422,
            );
        } catch (ProviderCommandFailedException $e) {
            return response()->json(
                ['message' => 'The provider could not complete the command.'],
                502,
            );
        }

        return response()->json(['data' => ['status' => $result->status]]);
    }

    public function destroy(Request $request, Device $device): JsonResponse
    {
        $this->authorize('delete', $device);

        $device->delete();

        return response()->json(null, 204);
    }
}
