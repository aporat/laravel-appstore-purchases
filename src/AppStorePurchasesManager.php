<?php

namespace Aporat\AppStorePurchases;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use ReceiptValidator\AbstractValidator;
use ReceiptValidator\AppleAppStore\Validator as AppleAppStoreValidator;
use ReceiptValidator\iTunes\Validator as iTunesValidator;
use ReceiptValidator\Environment;

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

        if (is_null($config)) {
            throw new InvalidArgumentException("App store validator [{$name}] is not defined.");
        }

        return $this->build($config);
    }

    /**
     * Build a validator with the given configuration.
     */
    public function build(array $config): AbstractValidator
    {
        $validatorMethod = 'create'.ucwords($config['validator']).'Validator';

        if (method_exists($this, $validatorMethod)) {
            return $this->{$validatorMethod}($config);
        }

        throw new InvalidArgumentException("Validator [{$config['validator']}] is not supported.");
    }

    protected function createAppleAppStoreValidator(array $config): AbstractValidator
    {
        $signingKey = file_get_contents($config['key_path']);

        return new AppleAppStoreValidator(
            signingKey: $signingKey,
            keyId: $config['key_id'],
            issuerId: $config['issuer_id'],
            bundleId: $config['bundle_id'],
            environment: Environment::PRODUCTION);
    }

    protected function createItunesValidator(array $config): AbstractValidator
    {
        return new iTunesValidator(
            sharedSecret: $config['shared_secret'],
            environment: Environment::PRODUCTION);
    }

    /**
     * Get the cache connection configuration.
     */
    protected function getConfig(string $name): ?array
    {
        if ($name !== 'null') {
            return $this->app['config']["appstore-purchases.validators.{$name}"];
        }

        return ['validator' => 'null'];
    }

    public function supportedValidators(): array
    {
        return ['apple-app-store', 'itunes'];
    }
}
