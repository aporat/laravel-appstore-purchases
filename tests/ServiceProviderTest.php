<?php

namespace Aporat\AppStorePurchases\Tests;

use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Orchestra\Testbench\TestCase;

class ServiceProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            AppStorePurchasesServiceProvider::class,
        ];
    }

    public function test_service_provider_boots()
    {
        $this->assertTrue(class_exists(AppStorePurchasesServiceProvider::class));
    }
}
