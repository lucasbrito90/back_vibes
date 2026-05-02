<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVibeRequest;
use App\Http\Requests\UpdateVibeRequest;
use App\Http\Resources\VibeResource;
use App\Models\Vibe;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Attributes\Controllers\Authorize;

class VibeController extends Controller
{
    #[Authorize('viewAny', Vibe::class)]
    public function index(Request $request): AnonymousResourceCollection
    {
        $vibes = Vibe::where('user_id', $request->user()->id)
            ->latest()
            ->get();

        return VibeResource::collection($vibes);
    }

    #[Authorize('create', Vibe::class)]
    public function store(StoreVibeRequest $request): VibeResource
    {
        $vibe = Vibe::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return new VibeResource($vibe);
    }

    #[Authorize('view', Vibe::class)]
    public function show(Request $request, Vibe $vibe): VibeResource
    {
        return new VibeResource($vibe);
    }

    #[Authorize('update', Vibe::class)]
    public function update(UpdateVibeRequest $request, Vibe $vibe): VibeResource
    {
        $vibe->update($request->validated());

        return new VibeResource($vibe);
    }

    #[Authorize('delete', Vibe::class)]
    public function destroy(Request $request, Vibe $vibe): JsonResponse
    {
        $vibe->delete();

        return response()->json(['message' => 'Vibe deleted.']);
    }
}
