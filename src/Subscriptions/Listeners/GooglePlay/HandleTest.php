<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions\Listeners\GooglePlay;

use Aporat\AppStorePurchases\Events\GooglePlay\Test;
use Aporat\AppStorePurchases\Subscriptions\GooglePlayLookup;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionLog;

/**
 * The Play Console's "Send test notification" button — the Play counterpart to
 * the App Store HandleTest listener.
 */
final class HandleTest
{
    public function handle(Test $event): void
    {
        $notification = $event->notification;

        // Info, not debug: general-log is capped at Level::Info, and a test
        // notification is a deliberate operator action that has to be visible.
        SubscriptionLog::info('Received Google Play test notification.', [
            'app_name' => GooglePlayLookup::appNameForPackage($notification->getPackageName()),
            'package_name' => $notification->getPackageName(),
            'notification' => $notification->getRawData(),
        ]);
    }
}
