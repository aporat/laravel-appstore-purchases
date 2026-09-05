<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use ReceiptValidator\AbstractValidator;
use ReceiptValidator\Amazon\Validator as AmazonValidator;
use ReceiptValidator\AppleAppStore\Validator as AppleAppStoreValidator;
use ReceiptValidator\Environment;
use ReceiptValidator\GooglePlay\Validator as GooglePlayValidator;
use ReceiptValidator\iTunes\Validator as iTunesValidator;
use RuntimeException;

class AppStorePurchasesManager
{
    /**
     * The application instance.
     */
    protected Application $app;

    /**
     * @var array<string, AbstractValidator>
     */
    protected array $validators = [];

    /**
     * Create a new app store manager instance.
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function get(string $name): AbstractValidator
    {
        return $this->validators[$name] ??= $this->resolve($name);
    }

    protected function resolve(string $name): AbstractValidator
    {
        $config = $this->getConfig($name);

        return $this->build($config);
    }

    /**
     * Retrieves the configuration array for the given validator name.
     *
     * @param  string  $name  The name of the validator.
     * @return array<string, mixed>
     */
    protected function getConfig(string $name): array
    {
        $config = $this->app['config']["appstore-purchases.validators.{$name}"];

        if (is_null($config)) {
            throw new InvalidArgumentException("App store validator [{$name}] is not defined.");
        }

        if (! isset($config['validator']) || ! is_string($config['validator'])) {
            throw new InvalidArgumentException("App store validator [{$name}] is missing required 'validator' key.");
        }

        if (! array_key_exists('environment', $config)) {
            throw new InvalidArgumentException("App store validator [{$name}] is missing required 'environment' key.");
        }

        if (is_string($config['environment'])) {
            $config['environment'] = Environment::fromString($config['environment']);
        } elseif (! $config['environment'] instanceof Environment) {
            throw new InvalidArgumentException("App store validator [{$name}] 'environment' must be a string or Environment instance.");
        }

        return $config;
    }

    /**
     * Build a validator with the given configuration.
     *
     * @param  array<string, mixed>  $config
     */
    public function build(array $config): AbstractValidator
    {
        $validatorMethod = 'create'.str_replace('-', '', ucwords($config['validator'], '-')).'Validator';

        if (method_exists($this, $validatorMethod)) {
            $validator = $this->{$validatorMethod}($config);

            if ($logger = $this->resolveLogger($config)) {
                $validator->setLogger($logger);
            }

            return $validator;
        }

        throw new InvalidArgumentException("Validator [{$config['validator']}] is not supported.");
    }

    /**
     * Resolve a PSR-3 logger for the given validator config.
     *
     * Checks the per-validator 'log_channel' key first, then falls back to the
     * global 'appstore-purchases.logging.channel' config value. Returns null
     * when no channel is configured, leaving the validator's NullLogger in place.
     *
     * @param  array<string, mixed>  $config
     */
    protected function resolveLogger(array $config): ?LoggerInterface
    {
        $channel = $config['log_channel'] ?? $this->app['config']['appstore-purchases.logging.channel'] ?? null;

        if (! is_string($channel) || $channel === '') {
            return null;
        }

        return $this->app->make('log')->channel($channel);
    }

    /**
     * Retrieves a list of supported validators.
     *
     * @return array<string>
     */
    public function supportedValidators(): array
    {
        return ['apple-app-store', 'itunes', 'amazon', 'google-play'];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createAppleAppStoreValidator(array $config): AbstractValidator
    {
        foreach (['key_path', 'key_id', 'issuer_id', 'bundle_id'] as $key) {
            if (! isset($config[$key]) || ! is_string($config[$key]) || $config[$key] === '') {
                throw new InvalidArgumentException("Apple App Store validator config is missing required '{$key}'.");
            }
        }

        if (! file_exists($config['key_path'])) {
            throw new RuntimeException("Signing key file does not exist at path: {$config['key_path']}");
        }

        if (! is_readable($config['key_path'])) {
            throw new RuntimeException("Signing key file is not readable at path: {$config['key_path']}");
        }

        $signingKey = file_get_contents($config['key_path']);

        if ($signingKey === false) {
            throw new RuntimeException("Failed to read signing key file at path: {$config['key_path']}");
        }

        return new AppleAppStoreValidator(
            signingKey: $signingKey,
            keyId: $config['key_id'],
            issuerId: $config['issuer_id'],
            bundleId: $config['bundle_id'],
            environment: $config['environment']
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createItunesValidator(array $config): AbstractValidator
    {
        if (! isset($config['shared_secret']) || ! is_string($config['shared_secret']) || $config['shared_secret'] === '') {
            throw new InvalidArgumentException("iTunes validator config is missing required 'shared_secret'.");
        }

        return new iTunesValidator(
            sharedSecret: $config['shared_secret'],
            environment: $config['environment']
        );
    }

    /**
     * Build a Google Play validator from a service-account key.
     *
     * Accepts either 'service_account_key_path' (path to the JSON key file) or
     * 'service_account_json' (the raw JSON contents), plus the app's 'package_name'.
     *
     * @param  array<string, mixed>  $config
     */
    protected function createGooglePlayValidator(array $config): AbstractValidator
    {
        if (! isset($config['package_name']) || ! is_string($config['package_name']) || $config['package_name'] === '') {
            throw new InvalidArgumentException("Google Play validator config is missing required 'package_name'.");
        }

        $json = $config['service_account_json'] ?? null;

        if (! is_string($json) || $json === '') {
            $path = $config['service_account_key_path'] ?? null;

            if (! is_string($path) || $path === '') {
                throw new InvalidArgumentException(
                    "Google Play validator config requires 'service_account_key_path' or 'service_account_json'."
                );
            }

            if (! file_exists($path)) {
                throw new RuntimeException("Service account key file does not exist at path: {$path}");
            }

            if (! is_readable($path)) {
                throw new RuntimeException("Service account key file is not readable at path: {$path}");
            }

            $json = file_get_contents($path);

            if ($json === false) {
                throw new RuntimeException("Failed to read service account key file at path: {$path}");
            }
        }

        return new GooglePlayValidator(
            packageName: $config['package_name'],
            credentials: $json,
            environment: $config['environment']
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createAmazonValidator(array $config): AbstractValidator
    {
        if (! isset($config['developer_secret']) || ! is_string($config['developer_secret']) || $config['developer_secret'] === '') {
            throw new InvalidArgumentException("Amazon validator config is missing required 'developer_secret'.");
        }

        return new AmazonValidator(
            developerSecret: $config['developer_secret'],
            environment: $config['environment']
        );
    }
}
