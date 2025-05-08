<?php

namespace Aporat\AppStorePurchases;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;

class AppStorePurchasesServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(AppStorePurchasesManager::class, function ($app) {
            return new AppStorePurchasesManager($app);
        });

        $this->app->alias(AppStorePurchasesManager::class, 'appstore-purchases');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/appstore-purchases.php' => config_path('appstore-purchases.php'),
        ], 'config');
    }

    /**
     * Provides the list of services offered by the application.
     *
     * @return array<string> Returns an array containing the provided services.
     */
    public function provides(): array
    {
        return [
            AppStorePurchasesManager::class,
        ];
    }
}
