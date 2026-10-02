<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Facades;

use Aporat\AppStorePurchases\AppStorePurchasesManager;
use Illuminate\Support\Facades\Facade;
use ReceiptValidator\AbstractValidator;

/**
 * Facade for the App Store Purchases manager.
 *
 * @method static AbstractValidator get(string $name, \ReceiptValidator\Environment|string|null $environment = null)
 * @method static AbstractValidator build(array<string, mixed> $config)
 * @method static array<string> supportedValidators()
 * @method static array<string, string> appleAppStoreValidatorsByBundleId()
 * @method static array<string, array<string, mixed>> googlePlayRtdnExpectationsByPackageName()
 *
 * @see AppStorePurchasesManager
 * @see AbstractValidator
 *
 * @mixin AppStorePurchasesManager
 */
final class AppStorePurchases extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'appstore-purchases';
    }
}
