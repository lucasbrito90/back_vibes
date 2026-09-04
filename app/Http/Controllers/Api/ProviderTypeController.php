<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProviderTypeResource;
use App\SmartHome\ProviderDescriptorRegistry;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProviderTypeController extends Controller
{
    public function index(ProviderDescriptorRegistry $descriptors): AnonymousResourceCollection
    {
        return ProviderTypeResource::collection($descriptors->all());
    }
}
