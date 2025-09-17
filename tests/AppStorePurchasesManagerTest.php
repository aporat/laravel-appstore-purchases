<?php

namespace Aporat\AppStorePurchases\Tests;

use Aporat\AppStorePurchases\AppStorePurchasesManager;
use Orchestra\Testbench\TestCase;
use ReceiptValidator\Amazon\Validator as AmazonValidator;
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

        $app['config']->set('appstore-purchases.validators.amazon', [
            'validator' => 'amazon',
            'developer_secret' => 'DEVELOPER_SECRET',
            'environment' => Environment::SANDBOX,
        ]);

        $app['config']->set('appstore-purchases.validators.unsupported', [
            'validator' => 'unsupported',
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

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_resolves_amazon_validator()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $validator = $manager->get('amazon');

        $this->assertInstanceOf(AmazonValidator::class, $validator);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_for_unsupported_validator()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Validator [unsupported] is not supported.');

        $manager->get('unsupported');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_throws_exception_when_signing_key_file_is_missing()
    {
        $this->app['config']->set('appstore-purchases.validators.apple-app-store.key_path', '/invalid/path/to/key.p8');

        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Signing key file does not exist at path');

        $manager->get('apple-app-store');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_passes_the_correct_environment()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $itunesValidator = $manager->get('itunes');
        $amazonValidator = $manager->get('amazon');
        $appleValidator = $manager->get('apple-app-store');

        $this->assertSame(Environment::SANDBOX, $itunesValidator->getEnvironment());
        $this->assertSame(Environment::SANDBOX, $amazonValidator->getEnvironment());
        $this->assertSame(Environment::SANDBOX, $appleValidator->getEnvironment());
    }
}
