<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDeviceRequest;
use App\Http\Requests\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\ProviderConnection;
use App\SmartHome\DeviceStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeviceController extends Controller
{
    use AuthorizesRequests;

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

    public function show(Request $request, Device $device): DeviceResource
    {
        $this->authorize('view', $device);

        return new DeviceResource($device);
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

    public function destroy(Request $request, Device $device): JsonResponse
    {
        $this->authorize('delete', $device);

        $device->delete();

        return response()->json(null, 204);
    }
}
