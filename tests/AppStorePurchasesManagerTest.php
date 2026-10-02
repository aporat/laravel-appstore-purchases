<?php

namespace Aporat\AppStorePurchases\Tests;

use Aporat\AppStorePurchases\AppStorePurchasesManager;
use Illuminate\Log\LogManager;
use InvalidArgumentException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReceiptValidator\Amazon\Validator as AmazonValidator;
use ReceiptValidator\AppleAppStore\Validator as AppleValidator;
use ReceiptValidator\Environment;
use ReceiptValidator\GooglePlay\Validator as GooglePlayValidator;
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

        $app['config']->set('appstore-purchases.validators.google-play', [
            'validator' => 'google-play',
            'package_name' => 'app.example',
            'service_account_key_path' => __DIR__.'/GooglePlay/certs/testServiceAccount.json',
            'environment' => Environment::PRODUCTION,
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
    public function it_resolves_google_play_validator()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $validator = $manager->get('google-play');

        $this->assertInstanceOf(GooglePlayValidator::class, $validator);
        $this->assertSame('app.example', $validator->getPackageName());
        $this->assertSame(Environment::PRODUCTION, $validator->getEnvironment());
        $this->assertContains('google-play', $manager->supportedValidators());
    }

    #[Test]
    public function it_resolves_google_play_validator_from_inline_json()
    {
        $this->app['config']->set('appstore-purchases.validators.google-play.service_account_key_path', null);
        $this->app['config']->set(
            'appstore-purchases.validators.google-play.service_account_json',
            file_get_contents(__DIR__.'/GooglePlay/certs/testServiceAccount.json')
        );

        $manager = new AppStorePurchasesManager($this->app);

        $this->assertInstanceOf(GooglePlayValidator::class, $manager->get('google-play'));
    }

    #[Test]
    public function it_throws_when_google_play_package_name_is_missing()
    {
        $this->app['config']->set('appstore-purchases.validators.google-play.package_name', '');

        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("missing required 'package_name'");

        $manager->get('google-play');
    }

    #[Test]
    public function it_throws_when_google_play_credentials_are_missing()
    {
        $this->app['config']->set('appstore-purchases.validators.google-play.service_account_key_path', null);

        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("requires 'service_account_key_path' or 'service_account_json'");

        $manager->get('google-play');
    }

    #[Test]
    public function it_throws_when_google_play_key_file_is_missing()
    {
        $this->app['config']->set('appstore-purchases.validators.google-play.service_account_key_path', '/invalid/path/key.json');

        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Service account key file does not exist at path');

        $manager->get('google-play');
    }

    #[Test]
    public function it_throws_exception_for_unsupported_validator()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(InvalidArgumentException::class);
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

    #[Test]
    public function it_caches_resolved_validators()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $this->assertSame($manager->get('itunes'), $manager->get('itunes'));
    }

    #[Test]
    public function it_returns_a_separate_instance_for_an_environment_override()
    {
        $this->app['config']->set('appstore-purchases.validators.itunes.environment', Environment::PRODUCTION);

        $manager = new AppStorePurchasesManager($this->app);

        $configured = $manager->get('itunes');
        $sandbox = $manager->get('itunes', Environment::SANDBOX);

        $this->assertNotSame($configured, $sandbox);
        $this->assertSame(Environment::SANDBOX, $sandbox->getEnvironment());

        // The override must not leak into the shared instance: a queue worker
        // handling a sandbox receipt would otherwise point every later
        // production lookup at the sandbox endpoint.
        $this->assertSame(Environment::PRODUCTION, $manager->get('itunes')->getEnvironment());
        $this->assertSame($sandbox, $manager->get('itunes', 'sandbox'));
    }

    #[Test]
    public function it_throws_when_an_environment_override_is_invalid()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("invalid 'environment' value: staging");

        $manager->get('itunes', 'staging');
    }

    #[Test]
    #[DataProvider('driverNameSpellings')]
    public function it_accepts_alternate_driver_name_spellings(string $spelling)
    {
        $this->app['config']->set('appstore-purchases.validators.spelled', [
            'validator' => $spelling,
            'key_path' => __DIR__.'/AppleAppStore/certs/testSigningKey.p8',
            'key_id' => 'TESTKEY123',
            'issuer_id' => 'ISSUER123',
            'bundle_id' => 'com.example.app',
            'environment' => Environment::SANDBOX,
        ]);

        $manager = new AppStorePurchasesManager($this->app);

        $this->assertInstanceOf(AppleValidator::class, $manager->get('spelled'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function driverNameSpellings(): array
    {
        return [
            'kebab' => ['apple-app-store'],
            'camel' => ['appleAppStore'],
            'studly' => ['AppleAppStore'],
            'snake' => ['apple_app_store'],
            'alias' => ['apple'],
        ];
    }

    #[Test]
    public function it_throws_when_validator_config_is_not_an_array()
    {
        $this->app['config']->set('appstore-purchases.validators.broken', 'itunes');

        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('configuration must be an array');

        $manager->get('broken');
    }

    #[Test]
    public function it_throws_when_a_key_file_is_empty()
    {
        $path = tempnam(sys_get_temp_dir(), 'key');
        $this->app['config']->set('appstore-purchases.validators.apple-app-store.key_path', $path);

        $manager = new AppStorePurchasesManager($this->app);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Signing key file is empty');

            $manager->get('apple-app-store');
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function it_converts_string_environment_to_enum()
    {
        $this->app['config']->set('appstore-purchases.validators.itunes.environment', 'production');

        $manager = new AppStorePurchasesManager($this->app);
        $validator = $manager->get('itunes');

        $this->assertSame(Environment::PRODUCTION, $validator->getEnvironment());
    }

    #[Test]
    public function it_throws_when_environment_is_neither_string_nor_environment_instance()
    {
        $this->app['config']->set('appstore-purchases.validators.itunes.environment', null);

        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'environment' must be a string or Environment instance");

        $manager->get('itunes');
    }

    #[Test]
    public function it_throws_when_validator_name_is_undefined()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('App store validator [missing] is not defined.');

        $manager->get('missing');
    }

    #[Test]
    public function it_throws_when_validator_key_is_missing()
    {
        $this->app['config']->set('appstore-purchases.validators.broken', [
            'environment' => Environment::SANDBOX,
        ]);

        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("missing required 'validator' key");

        $manager->get('broken');
    }

    #[Test]
    public function it_throws_when_environment_key_is_missing()
    {
        $this->app['config']->set('appstore-purchases.validators.broken', [
            'validator' => 'itunes',
            'shared_secret' => 'SECRET',
        ]);

        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("missing required 'environment' key");

        $manager->get('broken');
    }

    #[Test]
    public function it_throws_when_itunes_shared_secret_is_missing()
    {
        $this->app['config']->set('appstore-purchases.validators.itunes.shared_secret', '');

        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("iTunes validator config is missing required 'shared_secret'");

        $manager->get('itunes');
    }

    #[Test]
    public function it_throws_when_amazon_developer_secret_is_missing()
    {
        $this->app['config']->set('appstore-purchases.validators.amazon.developer_secret', '');

        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Amazon validator config is missing required 'developer_secret'");

        $manager->get('amazon');
    }

    #[Test]
    public function it_throws_when_apple_required_key_is_missing()
    {
        $this->app['config']->set('appstore-purchases.validators.apple-app-store.bundle_id', '');

        $manager = new AppStorePurchasesManager($this->app);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Apple App Store validator config is missing required 'bundle_id'");

        $manager->get('apple-app-store');
    }

    #[Test]
    public function it_passes_app_apple_id_to_the_apple_validator()
    {
        $this->app['config']->set('appstore-purchases.validators.apple-app-store.app_apple_id', 1234567890);

        $manager = new AppStorePurchasesManager($this->app);
        $validator = $manager->get('apple-app-store');

        $this->assertInstanceOf(AppleValidator::class, $validator);
        $this->assertSame(1234567890, $validator->getAppAppleId());
    }

    #[Test]
    public function it_accepts_app_apple_id_as_a_numeric_string_from_env()
    {
        $this->app['config']->set('appstore-purchases.validators.apple-app-store.app_apple_id', '1234567890');

        $manager = new AppStorePurchasesManager($this->app);
        $validator = $manager->get('apple-app-store');

        $this->assertInstanceOf(AppleValidator::class, $validator);
        $this->assertSame(1234567890, $validator->getAppAppleId());
    }

    #[Test]
    public function it_treats_a_missing_or_empty_app_apple_id_as_unset()
    {
        $validator = (new AppStorePurchasesManager($this->app))->get('apple-app-store');

        $this->assertInstanceOf(AppleValidator::class, $validator);
        $this->assertNull($validator->getAppAppleId());

        $this->app['config']->set('appstore-purchases.validators.apple-app-store.app_apple_id', '');

        $validator = (new AppStorePurchasesManager($this->app))->get('apple-app-store');

        $this->assertInstanceOf(AppleValidator::class, $validator);
        $this->assertNull($validator->getAppAppleId());
    }

    #[Test]
    public function it_throws_when_app_apple_id_is_not_a_positive_integer()
    {
        $this->app['config']->set('appstore-purchases.validators.apple-app-store.app_apple_id', 'not-a-number');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'app_apple_id' must be a positive integer");

        (new AppStorePurchasesManager($this->app))->get('apple-app-store');
    }

    #[Test]
    public function it_lists_apple_validators_by_bundle_id()
    {
        $this->app['config']->set('appstore-purchases.validators.second-app', [
            'validator' => 'apple',
            'key_path' => __DIR__.'/AppleAppStore/certs/testSigningKey.p8',
            'key_id' => 'TESTKEY123',
            'issuer_id' => 'ISSUER123',
            'bundle_id' => 'com.example.second',
            'environment' => Environment::PRODUCTION,
        ]);
        // The unpublished default config: an Apple entry with no bundle ID.
        $this->app['config']->set('appstore-purchases.validators.placeholder', [
            'validator' => 'apple-app-store',
            'bundle_id' => '',
            'environment' => Environment::SANDBOX,
        ]);
        // A second entry for an already-listed bundle ID loses to the first.
        $this->app['config']->set('appstore-purchases.validators.duplicate', [
            'validator' => 'AppleAppStore',
            'bundle_id' => 'com.example.app',
            'environment' => Environment::SANDBOX,
        ]);

        $manager = new AppStorePurchasesManager($this->app);

        $this->assertSame([
            'com.example.app' => 'apple-app-store',
            'com.example.second' => 'second-app',
        ], $manager->appleAppStoreValidatorsByBundleId());
    }

    #[Test]
    public function it_returns_no_bundle_ids_when_no_validators_are_configured()
    {
        $this->app['config']->set('appstore-purchases.validators', null);

        $this->assertSame([], (new AppStorePurchasesManager($this->app))->appleAppStoreValidatorsByBundleId());
    }

    #[Test]
    public function it_collects_google_play_rtdn_expectations_by_package_name()
    {
        $this->app['config']->set('appstore-purchases.validators', [
            'one' => [
                'validator' => 'google-play',
                'package_name' => 'com.example.one',
                'rtdn' => ['audience' => 'https://one.example.com/cb'],
            ],
            'two' => [
                'validator' => 'GooglePlay',
                'package_name' => 'com.example.two',
                'rtdn' => ['audience' => ['https://two.example.com/cb'], 'service_account_email' => 'two@example.iam.gserviceaccount.com'],
            ],
            'no-rtdn' => [
                'validator' => 'google-play',
                'package_name' => 'com.example.plain',
            ],
            'no-package' => [
                'validator' => 'google-play',
                'rtdn' => ['audience' => 'https://nowhere.example.com/cb'],
            ],
            'duplicate' => [
                'validator' => 'google',
                'package_name' => 'com.example.one',
                'rtdn' => ['audience' => 'https://loser.example.com/cb'],
            ],
            'apple' => [
                'validator' => 'apple-app-store',
                'package_name' => 'com.example.apple',
                'rtdn' => ['audience' => 'https://apple.example.com/cb'],
            ],
            'broken' => 'not-an-array',
        ]);

        $this->assertSame([
            'com.example.one' => ['audience' => 'https://one.example.com/cb'],
            'com.example.two' => ['audience' => ['https://two.example.com/cb'], 'service_account_email' => 'two@example.iam.gserviceaccount.com'],
        ], (new AppStorePurchasesManager($this->app))->googlePlayRtdnExpectationsByPackageName());
    }

    #[Test]
    public function it_returns_no_rtdn_expectations_when_no_validators_are_configured()
    {
        $this->app['config']->set('appstore-purchases.validators', null);

        $this->assertSame([], (new AppStorePurchasesManager($this->app))->googlePlayRtdnExpectationsByPackageName());
    }

    #[Test]
    public function it_exposes_supported_validators()
    {
        $manager = new AppStorePurchasesManager($this->app);

        $this->assertSame(['apple-app-store', 'itunes', 'amazon', 'google-play'], $manager->supportedValidators());
    }
}
