<?php

declare(strict_types=1);

namespace App\Providers;

use App\PushNotifications\Providers\FcmPushProvider;
use App\PushNotifications\Providers\NoopPushProvider;
use App\PushNotifications\PushProviderResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the push provider abstraction (Phase 6).
 *
 * Binds the FcmPushProvider (built from config/push_notifications.php) and the
 * PushProviderResolver as singletons. No jobs, events, Scheduler, or Smart Home
 * wiring is performed here.
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
    }
}
