<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Subscriptions;

use Aporat\AppStorePurchases\Facades\AppStorePurchases;
use Aporat\AppStorePurchases\Subscriptions\SubscriberRegistry;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionsServiceProvider;
use Aporat\AppStorePurchases\Subscriptions\ValidatorConfig;
use Illuminate\Support\ServiceProvider;

final class SubscriptionsServiceProviderTest extends SubscriptionsTestCase
{
    public function test_it_binds_the_registry_and_publishes_the_migrations(): void
    {
        self::assertSame($this->app->make(SubscriberRegistry::class), $this->app->make(SubscriberRegistry::class));

        $paths = ServiceProvider::pathsToPublish(SubscriptionsServiceProvider::class, 'appstore-purchases-migrations');
        self::assertCount(1, $paths);
        self::assertStringEndsWith('database/migrations', (string) array_key_first($paths));
    }

    public function test_the_manager_exposes_the_validator_config_the_layer_needs(): void
    {
        self::assertSame(['com.example.plan.monthly', 'com.example.plan.yearly'], AppStorePurchases::subscriptionProducts('apple'));
        self::assertSame([], AppStorePurchases::subscriptionProducts('missing'));
        self::assertSame('com.example.app', AppStorePurchases::validatorConfig('apple')['bundle_id'] ?? null);
        self::assertNull(AppStorePurchases::validatorConfig('missing'));
        self::assertSame('play', AppStorePurchases::googlePlayValidatorForPackage('com.example.android'));
        self::assertNull(AppStorePurchases::googlePlayValidatorForPackage('com.example.app'));
        self::assertNull(AppStorePurchases::googlePlayValidatorForPackage(''));
    }

    public function test_the_layer_reads_validator_config_without_the_manager(): void
    {
        self::assertSame(['com.example.plan.monthly', 'com.example.plan.yearly'], ValidatorConfig::products('apple'));
        self::assertSame([], ValidatorConfig::products('missing'));
        self::assertSame('com.example.app', ValidatorConfig::bundleId('apple'));
        self::assertNull(ValidatorConfig::bundleId('play'));
        self::assertSame('play', ValidatorConfig::playValidatorForPackage('com.example.android'));

        config(['appstore-purchases.validators.play.validator' => 'googlePlay']);
        self::assertSame('play', ValidatorConfig::playValidatorForPackage('com.example.android'), 'any spelling the manager accepts');

        config(['appstore-purchases.validators.play.subscription_products' => ['', 7, 'ok']]);
        self::assertSame(['ok'], ValidatorConfig::products('play'));
    }
}
