<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions\Listeners\AppStore;

use Aporat\AppStorePurchases\Events\Test;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionLog;
use Illuminate\Contracts\Support\Arrayable;

final class HandleTest
{
    /**
     * Handle the App Store test event.
     *
     * This listener captures test notifications and logs their content in a
     * structured format for easy debugging and inspection.
     */
    public function handle(Test $event): void
    {
        $notificationData = $event->notification;

        if ($notificationData instanceof Arrayable) {
            $notificationData = $notificationData->toArray();
        }

        SubscriptionLog::info(
            'Received Apple App Store test notification.',
            ['notification' => $notificationData]);
    }
}
