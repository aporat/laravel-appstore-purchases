<?php

namespace Aporat\AppStorePurchases\Events;

use Illuminate\Foundation\Events\Dispatchable;
use ReceiptValidator\AbstractTransaction;

class PurchaseEvent
{
    use Dispatchable;

    public function __construct(
        public AbstractTransaction $transaction
    ) {}
}
