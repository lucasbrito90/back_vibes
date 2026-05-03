<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SoundResource;
use App\Models\Sound;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SoundController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $sounds = Sound::orderBy('name')->get();

        return SoundResource::collection($sounds);
    }
}
