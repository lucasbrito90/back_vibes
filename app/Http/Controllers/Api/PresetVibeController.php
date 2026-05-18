<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePresetVibeRequest;
use App\Http\Requests\SyncPresetVibeSoundsRequest;
use App\Http\Requests\UpdatePresetVibeRequest;
use App\Http\Resources\PresetVibeResource;
use App\Models\PresetVibe;
use App\Models\PresetVibeSound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PresetVibeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PresetVibe::query()
            ->with(['coverBundle', 'presetVibeSounds.sound'])
            ->orderBy('name');

        $includeInactive = $request->boolean('include_inactive')
            && ($request->user()?->isAdminApproved() ?? false);

        if (! $includeInactive) {
            $query->where('is_active', true);
        }

        return PresetVibeResource::collection($query->get());
    }

    public function show(Request $request, PresetVibe $presetVibe): PresetVibeResource
    {
        if (! $presetVibe->is_active && ! ($request->user()?->isAdminApproved() ?? false)) {
            abort(404);
        }

        $presetVibe->load(['coverBundle', 'presetVibeSounds.sound']);

        return new PresetVibeResource($presetVibe);
    }

    public function store(StorePresetVibeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $preset = PresetVibe::query()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'cover_bundle_id' => $validated['cover_bundle_id'] ?? null,
            'category' => $validated['category'] ?? null,
            'tags' => $request->resolvedTags(),
            'is_active' => array_key_exists('is_active', $validated)
                ? (bool) $validated['is_active']
                : true,
        ]);

        $preset->load(['coverBundle', 'presetVibeSounds.sound']);

        return (new PresetVibeResource($preset))->response()->setStatusCode(201);
    }

    public function update(UpdatePresetVibeRequest $request, PresetVibe $presetVibe): PresetVibeResource
    {
        $validated = $request->validated();
        $payload = [];

        foreach (['name', 'description', 'cover_bundle_id', 'category'] as $field) {
            if (array_key_exists($field, $validated)) {
                $payload[$field] = $validated[$field];
            }
        }

        $tags = $request->resolvedTags();
        if ($tags !== null) {
            $payload['tags'] = $tags;
        }

        if (array_key_exists('is_active', $validated)) {
            $payload['is_active'] = (bool) $validated['is_active'];
        }

        if ($payload !== []) {
            $presetVibe->update($payload);
        }

        return new PresetVibeResource($presetVibe->fresh()->load(['coverBundle', 'presetVibeSounds.sound']));
    }

    public function destroy(PresetVibe $presetVibe): JsonResponse
    {
        $presetVibe->delete();

        return response()->json(['message' => 'Preset vibe deleted.']);
    }

    public function syncSounds(SyncPresetVibeSoundsRequest $request, PresetVibe $presetVibe): PresetVibeResource
    {
        $layers = $request->normalizedLayers();

        DB::transaction(function () use ($presetVibe, $layers): void {
            $presetVibe->presetVibeSounds()->delete();
            foreach ($layers as $row) {
                PresetVibeSound::query()->create([
                    'preset_vibe_id' => $presetVibe->id,
                    'sound_id' => $row['sound_id'],
                    'volume' => $row['volume'],
                    'sort_order' => $row['sort_order'],
                    'play_mode' => $row['play_mode'],
                    'loop' => $row['loop'],
                    'repeat_interval_seconds' => $row['repeat_interval_seconds'],
                    'start_offset_seconds' => $row['start_offset_seconds'],
                    'play_duration_seconds' => $row['play_duration_seconds'],
                ]);
            }
        });

        return new PresetVibeResource($presetVibe->fresh()->load(['coverBundle', 'presetVibeSounds.sound']));
    }
}
