<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions\Contracts;

use Aporat\AppStorePurchases\Subscriptions\ManagesStoreSubscriptions;
use Aporat\AppStorePurchases\Subscriptions\StoreSubscription;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ReceiptValidator\AppleAppStore\Transaction as AppStoreTransaction;

/**
 * An account that holds App Store and Google Play subscriptions.
 *
 * An Eloquent model implements this through
 * {@see ManagesStoreSubscriptions},
 * and the request, worker and webhook code in this package is written
 * against it. The verbs are the same for both stores:
 *
 * - `verify*`  a client-supplied credential: looked up at the store, may only
 *              ever extend the entitlement.
 * - `sync*`    an authoritative re-read (webhook, worker): applies whatever
 *              the store now reports, in either direction.
 * - `apply*`   a transaction the store already verified and handed over.
 * - `revoke*`  pull the entitlement a subscription granted.
 */
interface StoreSubscriber
{
    /** The `appstore-purchases.validators` entry whose App Store credentials verify this account's iOS purchases. */
    public function appStoreAppName(): string;

    /** The `appstore-purchases.validators` entry whose Google Play credentials verify this account's Android purchases. */
    public function playAppName(): string;

    /**
     * The account's rows in the `subscriptions` table, one per store.
     *
     * @return HasMany<covariant StoreSubscription, covariant Model>
     */
    public function subscriptions(): HasMany;

    // --- App Store ---

    /**
     * Apply a transaction from an App Store server notification. The package
     * has already verified it. Returns the new expiry date, or null if the
     * transaction was ignored or the entitlement did not move.
     */
    public function applyAppStoreTransaction(AppStoreTransaction $transaction, string $source = 'webhook'): ?CarbonInterface;

    /**
     * Look the subscription up on the App Store Server API and apply its
     * latest state. Any transaction id belonging to the subscription will
     * do; Apple resolves it to the original.
     */
    public function verifyAppStoreSubscription(string $transactionId, string $environment, string $source = 'client'): ?CarbonInterface;

    public function revokeAppStoreSubscription(string $originalTransactionId, string $source = 'webhook'): void;

    // --- Google Play ---

    /** Validate a client-supplied purchase token and apply it. */
    public function verifyPlaySubscription(string $purchaseToken, ?string $source = 'client'): ?CarbonInterface;

    /** Re-read the purchase from Play after a notification; a shortened expiry is applied. */
    public function syncPlaySubscription(string $purchaseToken, ?string $source = 'webhook'): ?CarbonInterface;

    /** Pull entitlement immediately, for a refunded or revoked purchase. */
    public function revokePlaySubscription(string $purchaseToken, ?string $source = 'webhook'): void;

    // --- Entitlement ---

    public function isSubscriptionActive(): bool;

    public function subscriptionEndsAt(): ?CarbonInterface;

    /** Expire the account right now, applying any app-specific side effects. */
    public function markSubscriptionExpired(): void;

    /**
     * The uuid the apps label purchases with (StoreKit's appAccountToken,
     * Play's obfuscatedAccountId), issued on first use.
     */
    public function ensureStoreAccountToken(): string;

    /**
     * The account's primary key, for logging.
     *
     * @return int|string|null
     */
    public function getKey();
}
