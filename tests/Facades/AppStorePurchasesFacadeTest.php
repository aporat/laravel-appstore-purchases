<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Facades;

use Aporat\AppStorePurchases\AppStorePurchasesManager;
use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Aporat\AppStorePurchases\Facades\AppStorePurchases;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use ReceiptValidator\Environment;
use ReceiptValidator\iTunes\Validator as iTunesValidator;

class AppStorePurchasesFacadeTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('appstore-purchases.validators.itunes', [
            'validator' => 'itunes',
            'shared_secret' => 'SHARED_SECRET',
            'environment' => Environment::SANDBOX,
        ]);
    }

    protected function getPackageProviders($app): array
    {
        return [
            AppStorePurchasesServiceProvider::class,
        ];
    }

    #[Test]
    public function it_resolves_manager_via_facade(): void
    {
        $this->assertInstanceOf(AppStorePurchasesManager::class, AppStorePurchases::getFacadeRoot());
    }

    #[Test]
    public function it_proxies_get_to_underlying_manager(): void
    {
        $this->assertInstanceOf(iTunesValidator::class, AppStorePurchases::get('itunes'));
    }

    #[Test]
    public function it_proxies_supported_validators(): void
    {
        $this->assertSame(['apple-app-store', 'itunes', 'amazon'], AppStorePurchases::supportedValidators());
    }
}
