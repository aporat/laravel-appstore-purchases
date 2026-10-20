<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions\Listeners\GooglePlay;

use Aporat\AppStorePurchases\Events\GooglePlay\PendingRefundReview;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionLog;

/**
 * Play opened a chargeback review and is asking whether to honour the refund.
 * Nothing is granted or revoked here: if the refund goes through, a voided
 * purchase notification follows and HandlePurchaseVoided pulls entitlement.
 *
 * The notification carries a pending-refund token rather than the purchase
 * token, so there is no account to resolve. It is logged so the review is
 * visible; answering it through the validator's reviewRefund() is a product
 * decision not taken yet.
 */
final class HandlePendingRefundReview
{
    use ResolvesPlayAccounts;

    public function handle(PendingRefundReview $event): void
    {
        $notification = $event->notification;
        $appName = $this->resolveAppName($notification);

        if ($appName === null) {
            return;
        }

        $payload = $notification->getPendingRefundReviewNotification();

        SubscriptionLog::warning('Play pending refund review received.', [
            'app_name' => $appName,
            'order_id' => $payload?->getOrderId(),
            'refund_reason' => $payload?->getRefundReason()?->name,
            'raw_refund_reason' => $payload?->getRawRefundReason(),
            'obfuscated_account_id' => $payload?->getObfuscatedAccountId(),
            'pending_refund_token' => $payload?->getPendingRefundToken(),
        ]);
    }
}
