<?php

namespace Aporat\AppStorePurchases;

use Illuminate\Support\ServiceProvider;

class AppStorePurchasesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register bindings or config here if needed
    }

    public function boot(): void
    {
        // $this->loadRoutesFrom(__DIR__ . '/../../routes/api.php');
        // $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        $this->publishes([
            __DIR__.'/../../config/appstore-purchases.php' => config_path('appstore-purchases.php'),
        ], 'config');
    }
}
