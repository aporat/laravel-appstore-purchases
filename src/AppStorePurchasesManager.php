<?php

namespace Aporat\AppStorePurchases;

use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use ReceiptValidator\AbstractValidator;
use ReceiptValidator\Amazon\Validator as AmazonValidator;
use ReceiptValidator\AppleAppStore\Validator as AppleAppStoreValidator;
use ReceiptValidator\Environment;
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

        if (is_null($config)) {
            throw new InvalidArgumentException("App store validator [{$name}] is not defined.");
        }

        return $this->build($config);
    }

    /**
     * Retrieves the configuration array for the given validator name.
     *
     * @param  string  $name  The name of the validator.
     * @return array<string, mixed>|null The configuration array for the specified validator, or null if not found.
     */
    protected function getConfig(string $name): ?array
    {
        if ($name !== 'null') {

            $config = $this->app['config']["appstore-purchases.validators.{$name}"];

            if (is_string($config['environment'])) {
                $config['environment'] = Environment::fromString($config['environment']);
            }

            return $config;
        }

        return ['validator' => 'null'];
    }

    /**
     * Build a validator with the given configuration.
     *
     * @param  array<string, mixed>  $config
     */
    public function build(array $config): AbstractValidator
    {
        $validatorMethod = 'create'.ucwords($config['validator']).'Validator';

        if (method_exists($this, $validatorMethod)) {
            return $this->{$validatorMethod}($config);
        }

        throw new InvalidArgumentException("Validator [{$config['validator']}] is not supported.");
    }

    /**
     * Retrieves a list of supported validators.
     *
     * @return array<string> An array containing the supported validator names.
     */
    public function supportedValidators(): array
    {
        return ['apple-app-store', 'itunes', 'amazon'];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createAppleAppStoreValidator(array $config): AbstractValidator
    {
        $signingKey = file_get_contents($config['key_path']);

        if ($signingKey === false) {
            throw new RuntimeException("Failed to read signing key file at path: {$config['key_path']}");
        }

        return new AppleAppStoreValidator(
            signingKey: $signingKey,
            keyId: $config['key_id'],
            issuerId: $config['issuer_id'],
            bundleId: $config['bundle_id'],
            environment: Environment::PRODUCTION);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createItunesValidator(array $config): AbstractValidator
    {
        return new iTunesValidator(
            sharedSecret: $config['shared_secret'],
            environment: Environment::PRODUCTION);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createAmazonValidator(array $config): AbstractValidator
    {
        return new AmazonValidator(
            developerSecret: $config['developer_secret'],
            environment: Environment::PRODUCTION);
    }
}
