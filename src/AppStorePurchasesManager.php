<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
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
     * Canonical driver name => factory method.
     *
     * @var array<string, string>
     */
    private const DRIVERS = [
        'apple-app-store' => 'createAppleAppStoreValidator',
        'itunes' => 'createItunesValidator',
        'amazon' => 'createAmazonValidator',
        'google-play' => 'createGooglePlayValidator',
    ];

    /**
     * Spellings of a driver name that are accepted in config, mapped onto the
     * canonical name. Keys are the normalised form (see normaliseDriver()).
     *
     * @var array<string, string>
     */
    private const DRIVER_ALIASES = [
        'apple' => 'apple-app-store',
        'app-store' => 'apple-app-store',
        'apple-appstore' => 'apple-app-store',
        'i-tunes' => 'itunes',
        'google' => 'google-play',
        'play' => 'google-play',
    ];

    /**
     * The application instance.
     */
    protected Application $app;

    /**
     * Resolved validators, keyed by name and (optional) environment override.
     *
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

    /**
     * Get the validator configured under the given name.
     *
     * Validators are cached per name, so the returned instance is shared with
     * every other caller. Pass $environment when a single call needs a
     * different environment from the configured one: that returns a separate,
     * separately cached instance rather than mutating the shared one, which
     * would otherwise leak the override into unrelated calls for the lifetime
     * of the process (queue workers, Octane).
     */
    public function get(string $name, Environment|string|null $environment = null): AbstractValidator
    {
        $environment = $environment === null ? null : $this->toEnvironment($environment, $name);

        $key = $name.'|'.($environment === null ? '' : $environment->value);

        return $this->validators[$key] ??= $this->resolve($name, $environment);
    }

    protected function resolve(string $name, ?Environment $environment = null): AbstractValidator
    {
        $config = $this->getConfig($name);

        if ($environment !== null) {
            $config['environment'] = $environment;
        }

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
        $config = $this->config()->get("appstore-purchases.validators.{$name}");

        if (is_null($config)) {
            throw new InvalidArgumentException("App store validator [{$name}] is not defined.");
        }

        if (! is_array($config)) {
            throw new InvalidArgumentException("App store validator [{$name}] configuration must be an array.");
        }

        if (! isset($config['validator']) || ! is_string($config['validator'])) {
            throw new InvalidArgumentException("App store validator [{$name}] is missing required 'validator' key.");
        }

        if (! array_key_exists('environment', $config)) {
            throw new InvalidArgumentException("App store validator [{$name}] is missing required 'environment' key.");
        }

        $config['environment'] = $this->toEnvironment($config['environment'], $name);

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * Build a validator with the given configuration.
     *
     * @param  array<string, mixed>  $config
     */
    public function build(array $config): AbstractValidator
    {
        $validatorName = is_scalar($config['validator'] ?? null) ? (string) $config['validator'] : '';
        $driver = $this->normaliseDriver($validatorName);

        if (! isset(self::DRIVERS[$driver])) {
            throw new InvalidArgumentException("Validator [{$validatorName}] is not supported.");
        }

        if (! array_key_exists('environment', $config)) {
            throw new InvalidArgumentException("Validator [{$validatorName}] is missing required 'environment' key.");
        }

        $config['environment'] = $this->toEnvironment($config['environment'], $driver);

        /** @var AbstractValidator $validator */
        $validator = $this->{self::DRIVERS[$driver]}($config);

        if ($logger = $this->resolveLogger($config)) {
            $validator->setLogger($logger);
        }

        return $validator;
    }

    /**
     * Fold the spellings a config file might use ('appleAppStore',
     * 'apple_app_store', 'AppleAppStore') onto one canonical driver name.
     */
    private function normaliseDriver(string $validator): string
    {
        $name = (string) preg_replace('/(?<!^)[A-Z]/', '-$0', trim($validator));
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name));
        $name = trim((string) preg_replace('/-+/', '-', $name), '-');

        return self::DRIVER_ALIASES[$name] ?? $name;
    }

    /**
     * Coerce a configured or caller-supplied environment onto the enum.
     */
    private function toEnvironment(mixed $environment, string $name): Environment
    {
        if ($environment instanceof Environment) {
            return $environment;
        }

        if (! is_string($environment)) {
            throw new InvalidArgumentException(
                "App store validator [{$name}] 'environment' must be a string or Environment instance."
            );
        }

        try {
            return Environment::fromString($environment);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(
                "App store validator [{$name}] has an invalid 'environment' value: {$environment}."
            );
        }
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
        $channel = $config['log_channel'] ?? $this->config()->get('appstore-purchases.logging.channel');

        if (! is_string($channel) || $channel === '') {
            return null;
        }

        /** @var LogManager $log */
        $log = $this->app->make('log');

        return $log->channel($channel);
    }

    /**
     * Names of the configured Apple App Store validators, keyed by bundle ID.
     *
     * Used by the server-notification endpoint to pick the validator a
     * notification must be verified against. Entries without a bundle ID
     * (the unpublished default config) are skipped. When two entries declare
     * the same bundle ID the first one wins.
     *
     * @return array<string, string>
     */
    public function appleAppStoreValidatorsByBundleId(): array
    {
        $names = [];

        foreach ($this->validatorConfigs() as $name => $config) {
            if (! is_string($config['validator'] ?? null) || $this->normaliseDriver($config['validator']) !== 'apple-app-store') {
                continue;
            }

            $bundleId = $config['bundle_id'] ?? null;

            if (is_string($bundleId) && $bundleId !== '') {
                $names[$bundleId] ??= $name;
            }
        }

        return $names;
    }

    /**
     * Per-app Real-time Developer Notification expectations, keyed by Play
     * package name.
     *
     * Collects the 'rtdn' block of every configured Google Play validator
     * entry (whatever spelling of the driver name it uses) that names a
     * package. The push verifier checks a notification carrying that package
     * name against this block instead of the global 'google_play.rtdn' one.
     *
     * @return array<string, array<string, mixed>>
     */
    public function googlePlayRtdnExpectationsByPackageName(): array
    {
        $apps = [];

        foreach ($this->validatorConfigs() as $config) {
            if (! is_string($config['validator'] ?? null) || $this->normaliseDriver($config['validator']) !== 'google-play') {
                continue;
            }

            $packageName = $config['package_name'] ?? null;
            $rtdn = $config['rtdn'] ?? null;

            if (is_string($packageName) && $packageName !== '' && is_array($rtdn)) {
                /** @var array<string, mixed> $rtdn */
                $apps[$packageName] ??= $rtdn;
            }
        }

        return $apps;
    }

    /**
     * Every configured validator entry that is an array, keyed by name.
     *
     * @return array<string, array<string, mixed>>
     */
    private function validatorConfigs(): array
    {
        $configs = $this->config()->get('appstore-purchases.validators');

        if (! is_array($configs)) {
            return [];
        }

        $arrays = [];

        foreach ($configs as $name => $config) {
            if (is_array($config)) {
                /** @var array<string, mixed> $config */
                $arrays[(string) $name] = $config;
            }
        }

        return $arrays;
    }

    private function config(): ConfigRepository
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make('config');

        return $config;
    }

    /**
     * Retrieves a list of supported validators.
     *
     * @return array<string>
     */
    public function supportedValidators(): array
    {
        return array_keys(self::DRIVERS);
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

        return new AppleAppStoreValidator(
            signingKey: $this->readKeyFile($config['key_path'], 'Signing key'),
            keyId: $config['key_id'],
            issuerId: $config['issuer_id'],
            bundleId: $config['bundle_id'],
            environment: $this->environmentOf($config),
            appAppleId: $this->toAppAppleId($config['app_apple_id'] ?? null),
        );
    }

    /**
     * The environment build() already coerced onto the enum.
     *
     * @param  array<string, mixed>  $config
     */
    private function environmentOf(array $config): Environment
    {
        $environment = $config['environment'] ?? null;

        if (! $environment instanceof Environment) {
            throw new InvalidArgumentException("Validator config 'environment' must be resolved before building."); // @codeCoverageIgnore
        }

        return $environment;
    }

    /**
     * Coerce the optional 'app_apple_id' config value onto an int.
     *
     * Env values arrive as strings, so numeric strings are accepted. Null and
     * the empty string (an unset env variable with an empty default) mean
     * "not configured".
     */
    private function toAppAppleId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        throw new InvalidArgumentException(
            "Apple App Store validator config 'app_apple_id' must be a positive integer."
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
            environment: $this->environmentOf($config)
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

            $json = $this->readKeyFile($path, 'Service account key');
        }

        return new GooglePlayValidator(
            packageName: $config['package_name'],
            credentials: $json,
            environment: $this->environmentOf($config)
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
            environment: $this->environmentOf($config)
        );
    }

    /**
     * Read a credential file, failing loudly rather than handing an empty
     * string to a validator that would then fail on every API call.
     */
    private function readKeyFile(string $path, string $label): string
    {
        if (! file_exists($path)) {
            throw new RuntimeException("{$label} file does not exist at path: {$path}");
        }

        if (! is_readable($path)) {
            throw new RuntimeException("{$label} file is not readable at path: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException("{$label} file is empty or could not be read at path: {$path}");
        }

        return $contents;
    }
}
