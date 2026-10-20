<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions\Listeners\AppStore;

use Aporat\AppStorePurchases\Events\OfferRedeemed;
use Aporat\AppStorePurchases\Events\PurchaseRefundReversed;
use Aporat\AppStorePurchases\Events\SubscriptionCreated;
use Aporat\AppStorePurchases\Events\SubscriptionRenewalChangedPref;
use Aporat\AppStorePurchases\Events\SubscriptionRenewalExtended;
use Aporat\AppStorePurchases\Events\SubscriptionRenewed;
use Aporat\AppStorePurchases\Subscriptions\SubscriberRegistry;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionLog;
use Throwable;

/**
 * Apply an App Store notification that carries the subscription's current
 * transaction to every account, in every subscriber pair, it belongs to. The package
 * has already verified the notification against the configured app.
 *
 * Every event here means "the entitlement now looks like this transaction":
 * DID_RENEW, SUBSCRIBED (first purchase, resubscribe, Family Sharing grant),
 * DID_CHANGE_RENEWAL_PREF (an upgrade takes effect at once with a new
 * transaction), OFFER_REDEEMED (an offer code redeemed outside the app),
 * RENEWAL_EXTENDED (Apple pushed the expiry out) and REFUND_REVERSED (a
 * refund HandleSubscriptionRevoked acted on was overturned). The expiry is
 * applied in either direction, so one path serves them all; without it the
 * account waits for the next client post or the hourly re-verify.
 */
final class HandleSubscriptionRenewed
{
    public function handle(SubscriptionRenewed|SubscriptionCreated|SubscriptionRenewalChangedPref|OfferRedeemed|SubscriptionRenewalExtended|PurchaseRefundReversed $event): void
    {
        $transaction = $event->notification->getTransaction();

        if ($transaction === null) {
            SubscriptionLog::warning('App Store transaction event skipped: notification carries no transaction.', [
                'notification_type' => $event->notification->getNotificationType()->value,
                'notification_uuid' => $event->notification->getNotificationUUID(),
            ]);

            return;
        }

        $originalTransactionId = $transaction->getOriginalTransactionId();
        $appAccountToken = $transaction->getAppAccountToken();
        $rawEvent = $transaction->getRawData();

        if (! $originalTransactionId) {
            SubscriptionLog::warning('App Store transaction event skipped due to missing original transaction ID.', [
                'notification_type' => $event->notification->getNotificationType()->value,
                'event' => $rawEvent,
            ]);

            return;
        }

        [$accounts, $matchedByToken] = (new SubscriberRegistry)->resolveAppStoreAccounts($appAccountToken, $originalTransactionId);

        $updated = 0;

        foreach ($accounts as $account) {
            try {
                if ($account->applyAppStoreTransaction($transaction)) {
                    $updated++;
                }
            } catch (Throwable $e) {
                SubscriptionLog::error('Failed to apply App Store transaction to account.', [
                    'original_transaction_id' => $originalTransactionId,
                    'account_id' => $account->getKey(),
                    'account_class' => $account::class,
                ], $e);
            }
        }

        SubscriptionLog::info('App Store transaction event handled.', [
            'notification_type' => $event->notification->getNotificationType()->value,
            'notification_subtype' => $event->notification->getSubtype()?->value,
            'event' => $rawEvent,
            'original_transaction_id' => $originalTransactionId,
            'app_account_token' => $appAccountToken,
            'matched_by_account_token' => $matchedByToken,
            'accounts_processed' => count($accounts),
            'accounts_updated' => $updated,
        ]);
    }
}
