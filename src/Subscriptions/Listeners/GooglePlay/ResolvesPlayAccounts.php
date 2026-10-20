<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions\Listeners\GooglePlay;

use Aporat\AppStorePurchases\Subscriptions\Contracts\StoreSubscriber;
use Aporat\AppStorePurchases\Subscriptions\GooglePlayLookup;
use Aporat\AppStorePurchases\Subscriptions\SubscriberRegistry;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionLog;
use Illuminate\Database\Eloquent\Model;
use ReceiptValidator\GooglePlay\ServerNotification;

/**
 * Shared plumbing for the Google Play RTDN listeners: mapping the package name
 * back to a configured app, and finding the accounts that hold a purchase token.
 */
trait ResolvesPlayAccounts
{
    /**
     * Match the notification's package name against the configured Android
     * apps. Returns null (after logging) for a package this API doesn't serve,
     * e.g. a topic shared with another project.
     */
    private function resolveAppName(ServerNotification $notification): ?string
    {
        $appName = GooglePlayLookup::appNameForPackage($notification->getPackageName());

        if ($appName === null) {
            SubscriptionLog::warning('Play RTDN ignored: unknown package name.', [
                'package_name' => $notification->getPackageName(),
                'notification' => $notification->getRawData(),
            ]);
        }

        return $appName;
    }

    /**
     * Every account holding this purchase token. Play's token is stored in the
     * subscription row's `store_subscription_id` slot (see
     * ManagesStoreSubscriptions::recordPlayPurchase).
     *
     * When no row holds the token yet — Play can publish the first PURCHASED
     * event before the app has posted the token to the subscription endpoint — the
     * purchase is fetched from Play and its obfuscatedAccountId is tried
     * instead: the account's `store_account_token`, which the Android app
     * attaches to the billing flow, the same way the App Store listeners fall
     * back to the appAccountToken the iOS app sets at purchase.
     *
     * Each subscriber keeps its own Account/Subscription pair, so the app name
     * picks which pair to search (see SubscriberRegistry::forPlayValidator).
     *
     * @return list<StoreSubscriber>
     */
    private function resolveAccounts(string $appName, string $purchaseToken): array
    {
        $accounts = $this->accountsByToken($appName, $purchaseToken);

        if ($accounts !== []) {
            return $accounts;
        }

        return $this->resolveAccountsByObfuscatedAccountId($appName, $purchaseToken);
    }

    /**
     * @return list<StoreSubscriber>
     */
    private function resolveAccountsByObfuscatedAccountId(string $appName, string $purchaseToken): array
    {
        $purchase = GooglePlayLookup::subscription($appName, $purchaseToken);
        $storeAccountToken = $purchase['obfuscated_account_id'] ?? null;

        if ($storeAccountToken === null || $storeAccountToken === '') {
            return [];
        }

        // Account uuids are stored upper-cased (see ManagesStoreSubscriptions::ensureStoreAccountToken).
        $account = $this->findAccountByStoreAccountToken($appName, strtoupper($storeAccountToken));

        if ($account === null) {
            SubscriptionLog::warning('Play RTDN: obfuscatedAccountId matches no account.', [
                'app_name' => $appName,
                'obfuscated_account_id' => $storeAccountToken,
            ]);

            return [];
        }

        SubscriptionLog::warning('Play RTDN: account resolved by obfuscatedAccountId.', [
            'app_name' => $appName,
            'account_id' => $account->getKey(),
        ]);

        return [$account];
    }

    /**
     * Every account in the app's module holding this purchase token.
     *
     * @return list<StoreSubscriber>
     */
    private function accountsByToken(string $appName, string $purchaseToken): array
    {
        $registry = new SubscriberRegistry;
        $subscriber = $registry->forPlayValidator($appName);

        if ($subscriber === null) {
            SubscriptionLog::warning('Play RTDN ignored: no subscriber is configured for this Play app.', [
                'app_name' => $appName,
            ]);

            return [];
        }

        return $registry->accountsHoldingRow($subscriber, $purchaseToken);
    }

    private function findAccountByStoreAccountToken(string $appName, string $storeAccountToken): ?StoreSubscriber
    {
        $subscriber = (new SubscriberRegistry)->forPlayValidator($appName);
        if ($subscriber === null) {
            return null;
        }

        /** @var (Model&StoreSubscriber)|null $account */
        $account = $subscriber['account']::query()->where('store_account_token', $storeAccountToken)->first();

        return $account;
    }
}
