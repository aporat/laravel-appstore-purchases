<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Events\GooglePlay;

use Illuminate\Foundation\Events\Dispatchable;
use ReceiptValidator\GooglePlay\ServerNotification;

/**
 * Base event for Google Play Real-time Developer Notifications.
 *
 * The notification only identifies the affected purchase token. Listeners should
 * re-read the purchase from the Android Publisher API (via the 'google-play'
 * validator's getSubscriptionPurchaseV2() or getProductPurchase()) before
 * granting or removing entitlement.
 */
class GooglePlayEvent
{
    use Dispatchable;

    public function __construct(
        public readonly ServerNotification $notification
    ) {}

    /**
     * The purchase token referenced by the notification, if any.
     */
    public function purchaseToken(): ?string
    {
        return $this->notification->getPurchaseToken();
    }
}
