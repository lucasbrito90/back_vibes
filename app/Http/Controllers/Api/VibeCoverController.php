<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApplyVibeCoverRequest;
use App\Http\Resources\VibeResource;
use App\Models\CoverBundle;
use App\Models\Vibe;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Applies a catalog cover bundle's imagery to a vibe.
 *
 * POST /api/vibes/{vibe}/cover
 *
 * The client only ever supplies a trusted `cover_bundle_id` — never raw image URLs. This is the
 * only way a vibe's thumbnail_url/artwork_url/player_background_url can be set: StoreVibeRequest
 * and UpdateVibeRequest deliberately exclude those fields (see the "visual URL fields are not
 * mass-assigned" test in VibeApiTest), so a vibe's cover always traces back to an admin-curated
 * CoverBundle row rather than an arbitrary client-supplied URL.
 */
final class VibeCoverController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(ApplyVibeCoverRequest $request, Vibe $vibe): VibeResource
    {
        $this->authorize('update', $vibe);

        $bundle = CoverBundle::query()->findOrFail($request->validated('cover_bundle_id'));

        if (! $bundle->is_active && ! ($request->user()?->isAdminApproved() ?? false)) {
            abort(404);
        }

        $vibe->update([
            'thumbnail_url' => $bundle->thumbnail_url,
            'artwork_url' => $bundle->artwork_url,
            'player_background_url' => $bundle->player_background_url,
        ]);

        $vibe->load('categories');
        $vibe->loadCount([
            'schedules as active_schedules_count' => fn ($q) => $q->where('is_enabled', true),
        ]);

        return new VibeResource($vibe);
    }
}
