<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases;

use Aporat\AppStorePurchases\Contracts\PubSubPushVerifier;
use Aporat\AppStorePurchases\GooglePlay\GoogleOidcPushVerifier;
use Aporat\AppStorePurchases\Logging\NotificationLogger;
use Aporat\AppStorePurchases\Support\NotificationReplayGuard;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
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

        $this->app->singleton(NotificationLogger::class, fn (Container $app) => new NotificationLogger($app));
        $this->app->singleton(NotificationReplayGuard::class, fn (Container $app) => new NotificationReplayGuard($app));

        $this->app->bind(PubSubPushVerifier::class, function (Container $app) {
            /** @var ConfigRepository $configRepository */
            $configRepository = $app->make('config');
            $config = $configRepository->get('appstore-purchases.google_play.rtdn');
            $config = is_array($config) ? $config : [];

            /** @var AppStorePurchasesManager $manager */
            $manager = $app->make(AppStorePurchasesManager::class);

            // Each value may be a string or a list of strings; empty values
            // are dropped by the verifier itself. The per-app map comes from
            // the Google Play validator entries, keyed by package name, which
            // is what the notification body identifies itself with.
            return new GoogleOidcPushVerifier(
                audience: self::stringOrList($config['audience'] ?? null),
                serviceAccountEmail: self::stringOrList($config['service_account_email'] ?? null),
                apps: $manager->googlePlayRtdnExpectationsByPackageName(),
                logger: $app->make(NotificationLogger::class),
            );
        });
    }

    /**
     * Narrow a config value to what the verifier accepts: a string, a list of
     * strings, or null. Anything else is treated as unset.
     *
     * @return string|list<string>|null
     */
    private static function stringOrList(mixed $value): string|array|null
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        return null;
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
            NotificationReplayGuard::class,
        ];
    }
}
