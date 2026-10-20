<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions\Listeners\AppStore;

use Aporat\AppStorePurchases\Events\PurchaseRefunded;
use Aporat\AppStorePurchases\Events\PurchaseRevoked;
use Aporat\AppStorePurchases\Subscriptions\SubscriberRegistry;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionLog;
use Throwable;

/**
 * Pull entitlement when Apple refunds (REFUND) or revokes (REVOKE) a purchase.
 *
 * Distinct from [HandleSubscriptionExpired]: an expiry arrives when the term
 * runs out, whereas these arrive mid-term and the subscription's own expiry
 * date keeps pointing at the future, so access has to be cut explicitly. The
 * Play side does the same thing via ManagesStoreSubscriptions::revokePlaySubscription().
 */
final class HandleSubscriptionRevoked
{
    public function handle(PurchaseRefunded|PurchaseRevoked $event): void
    {
        $transaction = $event->notification->getTransaction();

        if ($transaction === null) {
            SubscriptionLog::warning('Purchase refund event skipped: notification carries no transaction.', [
                'notification_type' => $event->notification->getNotificationType()->value,
                'notification_uuid' => $event->notification->getNotificationUUID(),
            ]);

            return;
        }

        $originalTransactionId = $transaction->getOriginalTransactionId();
        $appAccountToken = $transaction->getAppAccountToken();

        if (! $originalTransactionId) {
            SubscriptionLog::warning('Purchase refund event skipped due to missing original transaction ID.', [
                'event' => $transaction->getRawData(),
            ]);

            return;
        }

        [$accounts, $matchedByToken] = (new SubscriberRegistry)->resolveAppStoreAccounts($appAccountToken, $originalTransactionId);

        $updated = 0;

        foreach ($accounts as $account) {
            try {
                $account->revokeAppStoreSubscription($originalTransactionId, 'webhook');
                $updated++;
            } catch (Throwable $e) {
                SubscriptionLog::error('Failed to revoke individual subscription.', [
                    'original_transaction_id' => $originalTransactionId,
                    'account_id' => $account->getKey(),
                    'account_class' => $account::class,
                ], $e);
            }
        }

        SubscriptionLog::info('Purchase refund event handled.', [
            'event_type' => $event::class,
            'original_transaction_id' => $originalTransactionId,
            'app_account_token' => $appAccountToken,
            'matched_by_account_token' => $matchedByToken,
            'accounts_updated' => $updated,
        ]);
    }
}
