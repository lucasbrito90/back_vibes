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
    use AuthorizesRequests;

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Vibe::class);

        $vibes = Vibe::where('user_id', $request->user()->id)
            ->withCount('sounds')
            ->latest()
            ->get();

        return VibeResource::collection($vibes);
    }

    public function store(StoreVibeRequest $request): VibeResource
    {
        $this->authorize('create', Vibe::class);

        $vibe = Vibe::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);

        return new VibeResource($vibe);
    }

    public function show(Request $request, Vibe $vibe): VibeResource
    {
        $this->authorize('view', $vibe);

        $vibe->loadCount('sounds');

        return new VibeResource($vibe);
    }

    public function update(UpdateVibeRequest $request, Vibe $vibe): VibeResource
    {
        $this->authorize('update', $vibe);

        $vibe->update($request->validated());

        return new VibeResource($vibe);
    }

    public function destroy(Request $request, Vibe $vibe): JsonResponse
    {
        $this->authorize('delete', $vibe);

        $vibe->delete();

        return response()->json(['message' => 'Vibe deleted.']);
    }
}
