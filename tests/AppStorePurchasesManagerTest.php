<?php

namespace Aporat\AppStorePurchases\Tests;

use Aporat\AppStorePurchases\AppStorePurchasesManager;
use Orchestra\Testbench\TestCase;
use ReceiptValidator\AppleAppStore\Validator as AppleValidator;
use ReceiptValidator\Environment;
use ReceiptValidator\iTunes\Validator as iTunesValidator;

class AppStorePurchasesManagerTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('appstore-purchases.validators.apple-app-store', [
            'validator' => 'appleAppStore',
            'key_path' => __DIR__.'/AppleAppStore/certs/testSigningKey.p8',
            'key_id' => 'TESTKEY123',
            'issuer_id' => 'ISSUER123',
            'bundle_id' => 'com.example.app',
            'environment' => Environment::SANDBOX,
        ]);

        $app['config']->set('appstore-purchases.validators.itunes', [
            'validator' => 'itunes',
            'shared_secret' => 'SHARED_SECRET',
            'environment' => Environment::SANDBOX,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_resolves_apple_app_store_validator()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $validator = $manager->get('apple-app-store');

        $this->assertInstanceOf(AppleValidator::class, $validator);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_resolves_itunes_validator()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $validator = $manager->get('itunes');

        $this->assertInstanceOf(iTunesValidator::class, $validator);
    }
}
