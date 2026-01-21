<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Facades;

use Aporat\AppStorePurchases\AppStorePurchasesManager;
use Illuminate\Support\Facades\Facade;
use ReceiptValidator\AbstractValidator;

/**
 * Facade for the App Store Purchases manager.
 *
 * @method static AbstractValidator get(string $name)
 * @method static AbstractValidator build(array $config)
 * @method static array<string> supportedValidators()
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
