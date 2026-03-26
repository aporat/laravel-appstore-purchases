<?php

namespace Aporat\AppStorePurchases\Tests;

use Aporat\AppStorePurchases\AppStorePurchasesManager;
use Illuminate\Log\LogManager;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReceiptValidator\Amazon\Validator as AmazonValidator;
use ReceiptValidator\AppleAppStore\Validator as AppleValidator;
use ReceiptValidator\Environment;
use ReceiptValidator\iTunes\Validator as iTunesValidator;

class AppStorePurchasesManagerTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('appstore-purchases.validators.apple-app-store', [
            'validator' => 'apple-app-store',
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

    #[Test]
    public function it_resolves_apple_app_store_validator()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $validator = $manager->get('apple-app-store');

        $this->assertInstanceOf(AppleValidator::class, $validator);
    }

    #[Test]
    public function it_resolves_itunes_validator()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $validator = $manager->get('itunes');

        $this->assertInstanceOf(iTunesValidator::class, $validator);
    }

    #[Test]
    public function it_resolves_amazon_validator()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $validator = $manager->get('amazon');

        $this->assertInstanceOf(AmazonValidator::class, $validator);
    }

    #[Test]
    public function it_throws_exception_for_unsupported_validator()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Validator [unsupported] is not supported.');

        $manager->get('unsupported');
    }

    #[Test]
    public function it_throws_exception_when_signing_key_file_is_missing()
    {
        $this->app['config']->set('appstore-purchases.validators.apple-app-store.key_path', '/invalid/path/to/key.p8');

        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Signing key file does not exist at path');

        $manager->get('apple-app-store');
    }

    #[Test]
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

    #[Test]
    public function it_uses_null_logger_when_no_log_channel_is_configured()
    {
        $manager = new AppStorePurchasesManager($this->app);
        $validator = $manager->get('itunes');

        $logger = (new \ReflectionProperty($validator, 'logger'))->getValue($validator);

        $this->assertInstanceOf(NullLogger::class, $logger);
    }

    #[Test]
    public function it_injects_logger_when_global_log_channel_is_configured()
    {
        $this->app['config']->set('appstore-purchases.logging.channel', 'single');

        $manager = new AppStorePurchasesManager($this->app);
        $validator = $manager->get('itunes');

        $logger = (new \ReflectionProperty($validator, 'logger'))->getValue($validator);

        $this->assertInstanceOf(LoggerInterface::class, $logger);
        $this->assertNotInstanceOf(NullLogger::class, $logger);
    }

    #[Test]
    public function it_injects_logger_when_per_validator_log_channel_is_configured()
    {
        $this->app['config']->set('appstore-purchases.validators.itunes.log_channel', 'single');

        $manager = new AppStorePurchasesManager($this->app);
        $validator = $manager->get('itunes');

        $logger = (new \ReflectionProperty($validator, 'logger'))->getValue($validator);

        $this->assertInstanceOf(LoggerInterface::class, $logger);
        $this->assertNotInstanceOf(NullLogger::class, $logger);
    }

    #[Test]
    public function it_per_validator_log_channel_overrides_global_channel()
    {
        $this->app['config']->set('appstore-purchases.logging.channel', 'stack');
        $this->app['config']->set('appstore-purchases.validators.itunes.log_channel', 'single');

        $logManager = $this->createMock(LogManager::class);
        $logManager->expects($this->once())
            ->method('channel')
            ->with('single')
            ->willReturn($this->createStub(LoggerInterface::class));

        $this->app->instance('log', $logManager);

        $manager = new AppStorePurchasesManager($this->app);
        $manager->get('itunes');
    }

    #[Test]
    public function it_does_not_inject_logger_when_log_channel_is_empty_string()
    {
        $this->app['config']->set('appstore-purchases.logging.channel', '');

        $manager = new AppStorePurchasesManager($this->app);
        $validator = $manager->get('itunes');

        $logger = (new \ReflectionProperty($validator, 'logger'))->getValue($validator);

        $this->assertInstanceOf(NullLogger::class, $logger);
    }
}
