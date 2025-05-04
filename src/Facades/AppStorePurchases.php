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
    protected static function getFacadeAccessor(): string
    {
        return 'appstore-purchases';
    }
}
