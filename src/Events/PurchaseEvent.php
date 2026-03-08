<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Events;

use Illuminate\Foundation\Events\Dispatchable;
use ReceiptValidator\AppleAppStore\ServerNotification as AppleAppStoreServerNotification;

class PurchaseEvent
{
    use Dispatchable;

    public function __construct(
        public readonly AppleAppStoreServerNotification $notification
    ) {}
}
