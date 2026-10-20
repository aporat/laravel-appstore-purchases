<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Subscriptions;

use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Aporat\AppStorePurchases\Facades\AppStorePurchases;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionsServiceProvider;
use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\TestAccount;
use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\TestSubscription;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase;
use ReceiptValidator\AppleAppStore\Validator as AppleValidator;
use ReceiptValidator\GooglePlay\Validator as PlayValidator;

abstract class SubscriptionsTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AppStorePurchasesServiceProvider::class, SubscriptionsServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');

        $app['config']->set('appstore-purchases.validators', [
            'apple' => [
                'validator' => 'apple-app-store',
                'private_key' => "-----BEGIN PRIVATE KEY-----\nunused\n-----END PRIVATE KEY-----",
                'key_id' => 'KEY',
                'issuer_id' => 'ISSUER',
                'bundle_id' => 'com.example.app',
                'environment' => 'PRODUCTION',
                'subscription_products' => ['com.example.plan.monthly', 'com.example.plan.yearly'],
            ],
            'play' => [
                'validator' => 'google-play',
                'package_name' => 'com.example.android',
                'service_account_json' => '{}',
                'environment' => 'PRODUCTION',
                'subscription_products' => ['com.example.android.subscription'],
            ],
        ]);
        $app['config']->set('appstore-purchases.subscribers', [
            ['account' => TestAccount::class, 'subscription' => TestSubscription::class, 'play_validator' => 'play'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The bare accounts table an app would already have; the package's
        // own migrations add the subscriptions table and the two columns.
        Schema::create('accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

        TestAccount::reset();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function account(array $attributes = []): TestAccount
    {
        return TestAccount::query()->create($attributes);
    }

    /**
     * The validator the manager hands out for 'apple', as a mock. Pass null
     * to hand it out for every name. A plain facade mock, as an application
     * test would write it: the layer reads its config without the manager.
     */
    protected function fakeAppleValidator(?string $name = 'apple'): AppleValidator&MockInterface
    {
        $validator = Mockery::mock(AppleValidator::class);
        $validator->shouldReceive('setAppAccountToken')->byDefault();

        $expectation = AppStorePurchases::shouldReceive('get');
        if ($name !== null) {
            $expectation->withArgs(fn (string $given): bool => $given === $name);
        }
        $expectation->andReturn($validator);

        return $validator;
    }

    protected function fakePlayValidator(): PlayValidator&MockInterface
    {
        $validator = Mockery::mock(PlayValidator::class);

        AppStorePurchases::shouldReceive('get')->withArgs(fn (string $given): bool => $given === 'play')->andReturn($validator);

        return $validator;
    }

    protected function rows(TestAccount $account): Collection
    {
        return TestSubscription::query()->where('account_id', $account->id)->orderBy('store')->get();
    }
}
