<?php

declare(strict_types=1);

namespace App\Providers;

use App\SmartHome\Adapters\HomeAssistantAdapter;
use App\SmartHome\ProviderAdapterResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Smart Home provider adapters and the adapter resolver in the
 * service container.
 */
final class SmartHomeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HomeAssistantAdapter::class);
        $this->app->singleton(ProviderAdapterResolver::class);
    }
}
