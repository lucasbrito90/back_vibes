<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DebugMeResource;
use App\Models\User;
use Illuminate\Http\Request;

final class DebugMeController extends Controller
{
    public function __invoke(Request $request): DebugMeResource
    {
        $user = tap($request->user(), static function (User $authenticated): void {
            $authenticated->loadCount('vibes');
        });

        return new DebugMeResource($user);
    }
}
