<?php

namespace Aporat\AppStorePurchases\Tests;

use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Orchestra\Testbench\TestCase;

class ServiceProviderTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    protected function get_package_providers($app): array
    {
        return [
            AppStorePurchasesServiceProvider::class,
        ];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function test_service_provider_boots()
    {
        $this->assertTrue(class_exists(AppStorePurchasesServiceProvider::class));
    }
}
