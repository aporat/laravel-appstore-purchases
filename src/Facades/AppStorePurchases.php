<?php

namespace Aporat\AppStorePurchases\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \ReceiptValidator\AbstractValidator get(string|null $name = null)
 * @method static \ReceiptValidator\AbstractValidator build(array $config)
 * @method static array supportedValidators()
 *
 * @see \Aporat\AppStorePurchases\AppStorePurchasesManager
 * @see \ReceiptValidator\AbstractValidator;
 */
class AppStorePurchases extends Facade
{

    protected static function getFacadeAccessor(): string
    {
        return 'appstore-purchases';
    }
}
