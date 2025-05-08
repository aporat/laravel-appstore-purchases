<?php

namespace Aporat\AppStorePurchases\Facades;

use Illuminate\Support\Facades\Facade;
use ReceiptValidator\AbstractValidator;

/**
 * @method static AbstractValidator get(string|null $name = null)
 * @method static AbstractValidator build(array $config)
 * @method static AbstractValidator[] supportedValidators()
 *
 * @see \Aporat\AppStorePurchases\AppStorePurchasesManager
 * @see AbstractValidator
 */
class AppStorePurchases extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string The name of the binding in the service container.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'appstore-purchases';
    }
}
