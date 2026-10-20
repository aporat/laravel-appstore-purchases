<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions\Listeners\GooglePlay;

use Aporat\AppStorePurchases\Events\GooglePlay\OneTimeProductCanceled;
use Aporat\AppStorePurchases\Events\GooglePlay\OneTimeProductPurchased;
use Aporat\AppStorePurchases\Events\GooglePlay\OneTimeProductUnknown;
use Aporat\AppStorePurchases\Subscriptions\GooglePlayLookup;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionLog;

/**
 * One-time product events carry nothing this layer acts on (it manages
 * subscriptions), but they're worth a breadcrumb rather than a silent drop.
 */
final class HandleOneTimeProductNotification
{
    public function handle(OneTimeProductPurchased|OneTimeProductCanceled|OneTimeProductUnknown $event): void
    {
        $notification = $event->notification;
        $payload = $notification->getOneTimeProductNotification();

        SubscriptionLog::info('Play one-time product notification ignored: no actionable payload.', [
            'app_name' => GooglePlayLookup::appNameForPackage($notification->getPackageName()),
            'package_name' => $notification->getPackageName(),
            'notification_type' => $payload?->getNotificationType()->name,
            'raw_notification_type' => $payload?->getRawNotificationType(),
            'sku' => $payload?->getSku(),
        ]);
    }
}
