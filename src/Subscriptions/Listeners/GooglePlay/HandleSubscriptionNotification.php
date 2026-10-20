<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions\Listeners\GooglePlay;

use Aporat\AppStorePurchases\Events\GooglePlay\GooglePlayEvent;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionCanceled;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionCancellationScheduled;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionDeferred;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionExpired;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionInGracePeriod;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionItemsChanged;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionOnHold;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPaused;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPauseScheduleChanged;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPendingPurchaseCanceled;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPriceChangeConfirmed;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPriceChangeUpdated;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPriceStepUpConsentUpdated;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPurchased;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionRecovered;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionRenewed;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionRestarted;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionRevoked;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionUnknown;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionLog;
use Throwable;

/**
 * Google Play subscription lifecycle events — the Play counterpart to the
 * HandleSubscription* App Store listeners.
 *
 * The notification body is only ever a signal: every handled event re-reads
 * the authoritative purchase state back from the Android Publisher API (via
 * ManagesStoreSubscriptions::syncPlaySubscription), so a forged or replayed message can't
 * grant anything. The one exception is REVOKED, where Play keeps reporting an
 * expiry time, so entitlement has to be pulled explicitly.
 *
 * A single listener covers every subscription event because the reaction is
 * the same for all of them: ask Play what the purchase looks like now. New
 * types Play adds must be listed in handle() or Laravel's discovery never
 * routes them here; the package dispatches them regardless.
 */
final class HandleSubscriptionNotification
{
    use ResolvesPlayAccounts;

    public function handle(
        SubscriptionRecovered|SubscriptionRenewed|SubscriptionCanceled|SubscriptionPurchased|SubscriptionOnHold|SubscriptionInGracePeriod|SubscriptionRestarted|SubscriptionPriceChangeConfirmed|SubscriptionDeferred|SubscriptionPaused|SubscriptionPauseScheduleChanged|SubscriptionRevoked|SubscriptionExpired|SubscriptionItemsChanged|SubscriptionCancellationScheduled|SubscriptionPriceChangeUpdated|SubscriptionPendingPurchaseCanceled|SubscriptionPriceStepUpConsentUpdated|SubscriptionUnknown $event,
    ): void {
        $this->process($event);
    }

    private function process(GooglePlayEvent $event): void
    {
        $notification = $event->notification;
        $appName = $this->resolveAppName($notification);

        if ($appName === null) {
            return;
        }

        $payload = $notification->getSubscriptionNotification();
        $purchaseToken = $payload?->getPurchaseToken() ?? '';

        if ($payload === null || $purchaseToken === '') {
            SubscriptionLog::warning('Play RTDN skipped: missing purchase token.', [
                'app_name' => $appName,
                'notification' => $notification->getRawData(),
            ]);

            return;
        }

        $type = $payload->getNotificationType();
        $accounts = $this->resolveAccounts($appName, $purchaseToken);

        if ($accounts === []) {
            // Normal for the very first PURCHASED event: Play can publish it
            // before the app has posted the token to the subscription endpoint.
            SubscriptionLog::warning('Play RTDN skipped: no account holds this purchase token.', [
                'app_name' => $appName,
                'notification_type' => $type->name,
                'product_id' => $payload->getSubscriptionId(),
            ]);

            return;
        }

        $revoke = $type->revokesEntitlement();
        $updated = 0;

        foreach ($accounts as $account) {
            try {
                if ($revoke) {
                    $account->revokePlaySubscription($purchaseToken);
                    $updated++;

                    continue;
                }

                if ($account->syncPlaySubscription($purchaseToken)) {
                    $updated++;
                }
            } catch (Throwable $e) {
                SubscriptionLog::error('Failed to apply Play subscription notification.', [
                    'app_name' => $appName,
                    'account_id' => $account->getKey(),
                    'notification_type' => $type->name,
                ], $e);
            }
        }

        SubscriptionLog::info('Play subscription notification handled.', [
            'app_name' => $appName,
            // Include the raw int so a type Play adds later is still legible.
            'notification_type' => $type->name,
            'raw_notification_type' => $payload->getRawNotificationType(),
            'product_id' => $payload->getSubscriptionId(),
            'revoked' => $revoke,
            'accounts_processed' => count($accounts),
            'accounts_updated' => $updated,
        ]);
    }
}
