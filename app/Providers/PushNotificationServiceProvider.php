<?php

declare(strict_types=1);

namespace App\Providers;

use App\PushNotifications\Contracts\PushProviderResolver as PushProviderResolverContract;
use App\PushNotifications\Providers\FcmPushProvider;
use App\PushNotifications\Providers\NoopPushProvider;
use App\PushNotifications\PushProviderResolver;
use App\PushNotifications\Services\PushNotificationEvents;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the push provider abstraction (Phase 6) and the domain event entry
 * point (Phase 8).
 *
 * Binds the FcmPushProvider (built from config/push_notifications.php), the
 * PushProviderResolver, and the PushNotificationEvents orchestrator as
 * singletons. No Scheduler or Smart Home wiring is performed here — domains call
 * PushNotificationEvents themselves.
 */
final class PushNotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FcmPushProvider::class, function (Application $app): FcmPushProvider {
            /** @var array<string, mixed> $config */
            $config = (array) $app['config']->get('push_notifications.fcm', []);

            /** @var array<string, mixed>|string|null $credentials */
            $credentials = $config['credentials'] ?? null;

            return new FcmPushProvider(
                credentials: $credentials,
                projectId: (string) ($config['project_id'] ?? ''),
                scope: (string) ($config['scope'] ?? 'https://www.googleapis.com/auth/firebase.messaging'),
                httpTimeout: (int) ($config['http_timeout'] ?? 10),
                tokenCacheKey: (string) ($config['token_cache_key'] ?? 'push_notifications:fcm:oauth_token'),
                tokenExpirySkew: (int) ($config['token_expiry_skew'] ?? 60),
            );
        });

        $this->app->singleton(NoopPushProvider::class);

        $this->app->singleton(PushProviderResolver::class);

        // Bind the interface so the job and other callers can type-hint the contract.
        $this->app->alias(PushProviderResolver::class, PushProviderResolverContract::class);

        // Phase 8 — single entry point used by Scheduler and Smart Home.
        $this->app->singleton(PushNotificationEvents::class);
    }
}
