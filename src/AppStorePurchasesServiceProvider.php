<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases;

use Aporat\AppStorePurchases\Contracts\PubSubPushVerifier;
use Aporat\AppStorePurchases\GooglePlay\GoogleOidcPushVerifier;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class AppStorePurchasesServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton('appstore-purchases', function ($app) {
            return new AppStorePurchasesManager($app);
        });

        $this->mergeConfigFrom(__DIR__.'/../config/appstore-purchases.php', 'appstore-purchases');

        $this->app->bind(PubSubPushVerifier::class, function ($app) {
            $config = $app['config']['appstore-purchases.google_play.rtdn'] ?? [];

            return new GoogleOidcPushVerifier(
                audience: is_string($config['audience'] ?? null) && $config['audience'] !== '' ? $config['audience'] : null,
                serviceAccountEmail: is_string($config['service_account_email'] ?? null) && $config['service_account_email'] !== ''
                    ? $config['service_account_email']
                    : null,
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
            PubSubPushVerifier::class,
        ];
    }
}
