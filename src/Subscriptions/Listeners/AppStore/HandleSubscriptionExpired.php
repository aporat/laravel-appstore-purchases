<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions\Listeners\AppStore;

use Aporat\AppStorePurchases\Events\SubscriptionExpired;
use Aporat\AppStorePurchases\Subscriptions\SubscriberRegistry;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionLog;
use Throwable;

/**
 * An App Store subscription ran out. Pull the entitlement it granted from
 * every account holding it; a live subscription from the other store on the
 * same account survives.
 */
final class HandleSubscriptionExpired
{
    public function handle(SubscriptionExpired $event): void
    {
        $transaction = $event->notification->getTransaction();

        if ($transaction === null) {
            SubscriptionLog::warning('Subscription expired event skipped: notification carries no transaction.', [
                'notification_type' => $event->notification->getNotificationType()->value,
                'notification_uuid' => $event->notification->getNotificationUUID(),
            ]);

            return;
        }

        $originalTransactionId = $transaction->getOriginalTransactionId();
        $appAccountToken = $transaction->getAppAccountToken();
        $rawEvent = $transaction->getRawData();

        if (! $originalTransactionId) {
            SubscriptionLog::warning('Subscription expired event skipped due to missing original transaction ID.', [
                'event' => $rawEvent,
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
                SubscriptionLog::error('Failed to expire individual subscription.', [
                    'original_transaction_id' => $originalTransactionId,
                    'account_id' => $account->getKey(),
                    'account_class' => $account::class,
                ], $e);
            }
        }

        SubscriptionLog::info('Subscription expired event handled.', [
            'event' => $rawEvent,
            'original_transaction_id' => $originalTransactionId,
            'app_account_token' => $appAccountToken,
            'matched_by_account_token' => $matchedByToken,
            'accounts_updated' => $updated,
        ]);
    }
}
