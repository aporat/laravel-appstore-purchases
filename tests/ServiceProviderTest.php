<?php

namespace Aporat\AppStorePurchases\Tests;

use Aporat\AppStorePurchases\AppStorePurchasesManager;
use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ServiceProviderTest extends TestCase
{
    /**
     * Register the package service provider.
     *
     * @param  Application  $app
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AppStorePurchasesServiceProvider::class,
        ];
    }

    #[Test]
    public function it_registers_the_service_provider(): void
    {
        $this->assertTrue(class_exists(AppStorePurchasesServiceProvider::class));
        $this->assertTrue($this->app->bound('appstore-purchases'));
    }

    #[Test]
    public function it_resolves_the_manager_by_class_as_the_same_singleton(): void
    {
        $manager = $this->app->make(AppStorePurchasesManager::class);

        $this->assertInstanceOf(AppStorePurchasesManager::class, $manager);
        $this->assertSame($this->app->make('appstore-purchases'), $manager);
    }
}
