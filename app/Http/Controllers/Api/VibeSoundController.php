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

        return VibeSoundResource::collection($vibe->sounds()->get());
    }

    public function store(AttachVibeSoundRequest $request, Vibe $vibe): VibeSoundResource
    {
        $this->authorize('update', $vibe);

        $data     = $request->validated();
        $playMode = $data['play_mode'] ?? 'loop';

        $vibe->sounds()->attach($data['sound_id'], [
            'volume'                  => $data['volume'] ?? 80,
            'sort_order'              => $data['sort_order'] ?? 0,
            // loop is always derived from play_mode — never trusted from the client
            'loop'                    => $playMode === 'loop',
            'play_mode'               => $playMode,
            // repeat_interval_seconds is only meaningful for interval mode
            'repeat_interval_seconds' => $playMode === 'interval'
                ? ($data['repeat_interval_seconds'] ?? null)
                : null,
            'start_offset_seconds'    => $data['start_offset_seconds'] ?? null,
            'play_duration_seconds'   => $data['play_duration_seconds'] ?? null,
            'fade_in_seconds'         => $data['fade_in_seconds'] ?? null,
            'fade_out_seconds'        => $data['fade_out_seconds'] ?? null,
        ]);

        $sound = $vibe->sounds()->where('sounds.id', $data['sound_id'])->first();

        return new VibeSoundResource($sound);
    }

    public function update(UpdateVibeSoundRequest $request, Vibe $vibe, Sound $sound): VibeSoundResource
    {
        $this->authorize('update', $vibe);

        $data = $request->validated();

        // If play_mode is present in this request, re-derive loop and clean up interval
        if (array_key_exists('play_mode', $data)) {
            $playMode            = $data['play_mode'];
            $data['loop']        = $playMode === 'loop';
            $data['repeat_interval_seconds'] = $playMode === 'interval'
                ? ($data['repeat_interval_seconds'] ?? null)
                : null;
        } elseif (array_key_exists('repeat_interval_seconds', $data)) {
            // play_mode not sent but repeat_interval_seconds was — ignore it to avoid orphaned data
            unset($data['repeat_interval_seconds']);
        }

        $vibe->sounds()->updateExistingPivot($sound->id, $data);

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
