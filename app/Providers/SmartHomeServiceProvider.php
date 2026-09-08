<?php

declare(strict_types=1);

namespace App\Providers;

use App\SmartHome\ProviderAdapterRegistry;
use App\SmartHome\ProviderAdapterResolver;
use App\SmartHome\ProviderDescriptorRegistry;
use App\SmartHome\Services\ProviderDeviceSyncService;
use App\SmartHome\Services\VibeSmartHomeDispatchService;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Smart Home provider adapters and the adapter registry in the
 * service container (ADR-032 decision B).
 */
final class SmartHomeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProviderAdapterRegistry::class);

        foreach (config('smart_home.adapters', []) as $adapterClass) {
            if (is_string($adapterClass)) {
                $this->app->singleton($adapterClass);
            }
        }

        $this->app->singleton(ProviderDescriptorRegistry::class);
        $this->app->singleton(ProviderAdapterResolver::class);
        $this->app->singleton(ProviderDeviceSyncService::class);
        $this->app->singleton(VibeSmartHomeDispatchService::class);
    }
}
