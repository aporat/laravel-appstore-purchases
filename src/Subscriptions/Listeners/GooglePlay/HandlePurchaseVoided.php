<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions\Listeners\GooglePlay;

use Aporat\AppStorePurchases\Events\GooglePlay\PurchaseVoided;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionLog;
use Throwable;

/**
 * A voided purchase is a refund or chargeback. Play keeps reporting an expiry
 * time on the purchase, so entitlement has to be pulled explicitly rather than
 * re-synced — the Play counterpart to HandleSubscriptionRevoked.
 */
final class HandlePurchaseVoided
{
    use ResolvesPlayAccounts;

    public function handle(PurchaseVoided $event): void
    {
        $notification = $event->notification;
        $appName = $this->resolveAppName($notification);

        if ($appName === null) {
            return;
        }

        $payload = $notification->getVoidedPurchaseNotification();
        $purchaseToken = $payload?->getPurchaseToken() ?? '';

        if ($payload === null || $purchaseToken === '') {
            SubscriptionLog::warning('Play voided purchase skipped: missing purchase token.', [
                'app_name' => $appName,
                'notification' => $notification->getRawData(),
            ]);

            return;
        }

        $accounts = $this->resolveAccounts($appName, $purchaseToken);
        $updated = 0;

        foreach ($accounts as $account) {
            try {
                $account->revokePlaySubscription($purchaseToken);
                $updated++;
            } catch (Throwable $e) {
                SubscriptionLog::error('Failed to void Play purchase.', [
                    'app_name' => $appName,
                    'account_id' => $account->getKey(),
                ], $e);
            }
        }

        SubscriptionLog::info('Play voided purchase handled.', [
            'app_name' => $appName,
            'order_id' => $payload->getOrderId(),
            'product_type' => $payload->getProductType()->name,
            'refund_type' => $payload->getRefundType()->name,
            'accounts_updated' => $updated,
        ]);
    }
}
