<?php

namespace Aporat\AppStorePurchases\Facades;

use Illuminate\Support\Facades\Facade;

class AppStorePurchases extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'appstore-purchases';
    }
}
