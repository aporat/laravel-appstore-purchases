<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases;

use Aporat\AppStorePurchases\Contracts\PubSubPushVerifier;
use Aporat\AppStorePurchases\GooglePlay\GoogleOidcPushVerifier;
use Aporat\AppStorePurchases\Logging\NotificationLogger;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class AppStorePurchasesServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton('appstore-purchases', function ($app) {
            return new AppStorePurchasesManager($app);
        });
        $this->app->alias('appstore-purchases', AppStorePurchasesManager::class);

        $this->mergeConfigFrom(__DIR__.'/../config/appstore-purchases.php', 'appstore-purchases');

        $this->app->singleton(NotificationLogger::class, fn ($app) => new NotificationLogger($app));

        $this->app->bind(PubSubPushVerifier::class, function ($app) {
            $config = $app['config']['appstore-purchases.google_play.rtdn'] ?? [];

            // A Google Play validator entry may carry its own 'rtdn' block, for
            // an API that serves several Play apps whose push subscriptions
            // sign from different Cloud projects. Keyed here by package name,
            // which is what the notification body identifies itself with.
            $apps = [];
            foreach ($app['config']['appstore-purchases.validators'] ?? [] as $validator) {
                if (! is_array($validator) || ! in_array($validator['validator'] ?? null, ['google-play', 'google', 'play'], true)) {
                    continue;
                }

                $packageName = $validator['package_name'] ?? null;
                $rtdn = $validator['rtdn'] ?? null;

                if (is_string($packageName) && $packageName !== '' && is_array($rtdn)) {
                    $apps[$packageName] = $rtdn;
                }
            }

            // Each value may be a string or a list of strings; empty values
            // are dropped by the verifier itself.
            return new GoogleOidcPushVerifier(
                audience: $config['audience'] ?? null,
                serviceAccountEmail: $config['service_account_email'] ?? null,
                apps: $apps,
                logger: $app->make(NotificationLogger::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/appstore-purchases.php' => config_path('appstore-purchases.php'),
        ], 'config');
    }

    /**
     * Provides the list of services offered by the application.
     *
     * @return array<string>
     */
    public function provides(): array
    {
        return [
            'appstore-purchases',
            AppStorePurchasesManager::class,
            PubSubPushVerifier::class,
            NotificationLogger::class,
        ];
    }
}
