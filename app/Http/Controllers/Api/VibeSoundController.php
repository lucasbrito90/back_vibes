<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttachVibeSoundRequest;
use App\Http\Requests\UpdateVibeSoundRequest;
use App\Http\Resources\VibeSoundResource;
use App\Models\Sound;
use App\Models\Vibe;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VibeSoundController extends Controller
{
    use AuthorizesRequests;

    public function index(Vibe $vibe): AnonymousResourceCollection
    {
        $this->authorize('view', $vibe);

        $sounds = $vibe->sounds()->get();

        return VibeSoundResource::collection($sounds);
    }

    public function store(AttachVibeSoundRequest $request, Vibe $vibe): VibeSoundResource
    {
        $this->authorize('update', $vibe);

        $data = $request->validated();

        $vibe->sounds()->attach($data['sound_id'], [
            'volume'                => $data['volume'] ?? 80,
            'loop'                  => $data['loop'] ?? true,
            'sort_order'            => $data['sort_order'] ?? 0,
            'start_offset_seconds'  => $data['start_offset_seconds'] ?? null,
            'play_duration_seconds' => $data['play_duration_seconds'] ?? null,
            'fade_in_seconds'       => $data['fade_in_seconds'] ?? null,
            'fade_out_seconds'      => $data['fade_out_seconds'] ?? null,
        ]);

        $sound = $vibe->sounds()->where('sounds.id', $data['sound_id'])->first();

        return new VibeSoundResource($sound);
    }

    public function update(UpdateVibeSoundRequest $request, Vibe $vibe, Sound $sound): VibeSoundResource
    {
        $this->authorize('update', $vibe);

        $vibe->sounds()->updateExistingPivot($sound->id, $request->validated());

        $sound = $vibe->sounds()->where('sounds.id', $sound->id)->first();

        return new VibeSoundResource($sound);
    }

    public function destroy(Vibe $vibe, Sound $sound): JsonResponse
    {
        $this->authorize('update', $vibe);

        $vibe->sounds()->detach($sound->id);

        return response()->json(['message' => 'Sound removed from vibe.']);
    }
}
