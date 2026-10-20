<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions;

use Aporat\AppStorePurchases\Facades\AppStorePurchases;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ReceiptValidator\AppleAppStore\Transaction as AppStoreTransaction;
use ReceiptValidator\AppleAppStore\Validator as AppleAppStoreValidator;
use ReceiptValidator\GooglePlay\SubscriptionOfferPhase;
use Throwable;

/**
 * The subscription lifecycle for an account model, which implements
 * {@see Contracts\StoreSubscriber} through it.
 *
 * Writes come from four sources and are labeled as such on the row
 * (`last_checked_by`): a client posting its purchase (`client`), a store
 * notification (`webhook`) and the re-verify worker (`worker`); `store`
 * says which store. The rules that hold across all of them:
 *
 * - One `subscriptions` row per (account, store). An account that bought on
 *   both platforms holds two rows, and its entitlement is the furthest-out
 *   expiry of the set.
 * - A client-supplied credential can only ever *extend* `accounts.subscription_end`:
 *   a stale or replayed token must not cut an active subscription short.
 *   Authoritative paths (webhook, RTDN, worker) apply whatever the store now
 *   reports, in either direction. The same goes for the store row: a client
 *   post never replaces a later `expires_at` with an earlier one.
 * - Each write runs under the account's row lock, see [withSubscriptionLock],
 *   so concurrent writes for one account apply in turn instead of one undoing
 *   another with an expiry it read before the other landed.
 * - Every store write, even one that leaves the expiry where it was,
 *   `touch()`es the account: a subscriber who never opens the app is still
 *   active as far as the retention sweep is concerned.
 * - `subscriptionChanged()` runs after every write that may have moved the
 *   entitlement, so the app can keep derived state (a plan column, a
 *   per-profile premium flag) in step without wrapping each path.
 * - Every write that starts a subscription on a store, or renews one, is
 *   handed to the app's activity log through [logSubscriptionActivity],
 *   see [logSubscriptionWrite]; re-verifies that find the same transaction
 *   are not.
 * - A client post records only on the account the purchase is labelled
 *   for: a transaction or purchase carrying a token another live account
 *   holds is refused, see [appStoreTransactionBelongsToAnotherAccount] and
 *   [playPurchaseBelongsToAnotherAccount], unless the app shares one
 *   purchase across accounts ([sharesSubscriptionAcrossAccounts]: the
 *   posting account gets its own row for the same subscription and the
 *   label stays with the holder). Webhooks and the worker reach an account
 *   through its own subscription row and are not checked.
 *
 * Method names follow one scheme for both stores, see {@see Contracts\StoreSubscriber}:
 * `verify*` (client credential, extend only), `sync*` (authoritative
 * re-read), `apply*` (verified transaction in hand), `revoke*`.
 *
 * The using model supplies the two validator names and a `subscriptions()`
 * HasMany relation to its own {@see StoreSubscription} subclass, and has the
 * `subscription_end` and `store_account_token` columns (see
 * database/migrations); nothing here is app-specific. What is logged goes
 * through {@see SubscriptionLog}.
 *
 * @property string|null $store_account_token the uuid the apps label purchases with: StoreKit's appAccountToken on iOS, Play's obfuscatedAccountId on Android
 * @property Carbon|null $subscription_end the account's entitlement, the furthest-out expiry across its store rows
 *
 * @mixin Model
 */
trait ManagesStoreSubscriptions
{
    /** Apple's `inAppOwnershipType` for a transaction a family member receives through Family Sharing. */
    private const string APP_STORE_OWNERSHIP_FAMILY_SHARED = 'FAMILY_SHARED';

    abstract public function appStoreAppName(): string;

    abstract public function playAppName(): string;

    /**
     * Hook for module-specific side effects after the entitlement may have
     * moved. The account's `subscription_end` is current when it runs.
     */
    protected function subscriptionChanged(): void {}

    /**
     * Hook for the app's activity log: one `subscription_started`,
     * `subscription_offer_started` or `subscription_renewed` line per store
     * write that moved the subscription, see [logSubscriptionWrite]. $actor
     * is `user` for a client post, `system` for a store notification or the
     * re-verify worker. Nothing is written unless the model overrides it.
     *
     * @param  array<string, mixed>  $context
     */
    protected function logSubscriptionActivity(string $event, array $context, string $actor): void {}

    /**
     * Whether one purchase may unlock more than one account. Off, a client
     * post carrying another live account's token is refused so one
     * subscription unlocks one account. On (an app whose usage limits are
     * metered per subscription rather than per account), it is recorded on
     * the posting account as well: a user who bought on one account and
     * signs in to another on the same device is premium on both.
     */
    protected function sharesSubscriptionAcrossAccounts(): bool
    {
        return false;
    }

    // --- Entitlement ---

    public function isSubscriptionActive(): bool
    {
        return $this->subscription_end !== null && $this->subscription_end->isFuture();
    }

    public function subscriptionEndsAt(): ?CarbonInterface
    {
        return $this->subscription_end;
    }

    public function markSubscriptionExpired(): void
    {
        $this->update(['subscription_end' => now()]);
        $this->subscriptionChanged();
    }

    /**
     * The account's stable cross-store identifier, issued on first use and
     * sent to the apps in every account block. The iOS app sets it as the
     * purchase's appAccountToken and the Android app as
     * BillingFlowParams.obfuscatedAccountId, so a store notification can be
     * routed to this account even before the client has posted its
     * transaction or purchase token.
     */
    public function ensureStoreAccountToken(): string
    {
        if (empty($this->store_account_token)) {
            $this->update(['store_account_token' => strtoupper((string) Str::uuid())]);
        }

        return (string) $this->store_account_token;
    }

    // --- App Store ---

    /**
     * Apply an already-verified transaction from an App Store server
     * notification. There is no outbound call to throttle here, so there is
     * no rate limiting either: a renewal that lands right after the user
     * opened the app must still be recorded.
     */
    public function applyAppStoreTransaction(AppStoreTransaction $transaction, string $source = 'webhook'): ?CarbonInterface
    {
        if (! $this->isSubscriptionProduct($transaction->getProductId())) {
            return null;
        }

        return $this->recordAppStoreTransaction($transaction, $source);
    }

    public function verifyAppStoreSubscription(string $transactionId, string $environment, string $source = 'client'): ?CarbonInterface
    {
        $latest = AppStoreLookup::latestSubscription($this->appStoreAppName(), $environment, $transactionId);
        $transaction = $latest?->getTransactionInfo();

        if ($transaction === null) {
            return null;
        }

        return $this->recordAppStoreTransaction($transaction, $source);
    }

    public function revokeAppStoreSubscription(string $originalTransactionId, string $source = 'webhook'): void
    {
        $this->revokeSubscriptionRow($originalTransactionId, $source);
    }

    // --- Google Play ---

    public function verifyPlaySubscription(string $purchaseToken, ?string $source = 'client'): ?CarbonInterface
    {
        $purchase = GooglePlayLookup::subscription($this->playAppName(), $purchaseToken);

        if ($purchase === null) {
            return null;
        }

        return $this->recordPlayPurchase($purchaseToken, $purchase, $source);
    }

    /**
     * Re-sync against Play after a Real-time Developer Notification. Play is
     * authoritative here, unlike the client-driven [verifyPlaySubscription]:
     * a *shortened* expiry (expiry, refund, revoke) is applied rather than
     * ignored, so entitlement is actually taken away.
     */
    public function syncPlaySubscription(string $purchaseToken, ?string $source = 'webhook'): ?CarbonInterface
    {
        $purchase = GooglePlayLookup::subscription($this->playAppName(), $purchaseToken);

        if ($purchase === null) {
            return null;
        }

        return $this->recordPlayPurchase($purchaseToken, $purchase, $source, authoritative: true);
    }

    /**
     * Revoke entitlement immediately, for a refunded or revoked Play purchase.
     * Play still reports an expiry time on those, so the expiry alone can't be
     * trusted to end access.
     */
    public function revokePlaySubscription(string $purchaseToken, ?string $source = 'webhook'): void
    {
        $this->revokeSubscriptionRow($purchaseToken, $source ?? 'webhook');
    }

    // --- Shared write paths ---

    private function isSubscriptionProduct(?string $productId): bool
    {
        return $productId !== null && in_array($productId, ValidatorConfig::products($this->appStoreAppName()), true);
    }

    private function recordAppStoreTransaction(AppStoreTransaction $transaction, string $source): ?CarbonInterface
    {
        $expiresAt = $transaction->getExpiresDate();
        if ($expiresAt === null) {
            return null;
        }

        $heldElsewhere = $source === 'client' && $this->appStoreTransactionBelongsToAnotherAccount($transaction);
        if ($heldElsewhere && ! $this->sharesSubscriptionAcrossAccounts()) {
            return null;
        }

        [$previous, $row] = $this->withSubscriptionLock(fn (): array => $this->writeSubscriptionRow(StoreSubscription::STORE_APP_STORE, [
            'last_checked_at' => now(),
            'environment' => $transaction->getEnvironment()->value,
            'store_subscription_id' => $transaction->getOriginalTransactionId(),
            'store_transaction_id' => $transaction->getTransactionId(),
            'starts_at' => $transaction->getOriginalPurchaseDate(),
            'expires_at' => $expiresAt,
            'last_checked_by' => $source,
        ], authoritative: $source !== 'client'));

        $this->logSubscriptionWrite($this->appStoreAppName(), $previous, $row, $source, [
            'product_id' => $transaction->getProductId(),
            // PURCHASE or RENEWAL on a signed transaction; null on older ones.
            'transaction_reason' => $transaction->getTransactionReason(),
            'price' => $transaction->getPrice(),
            'currency' => $transaction->getCurrency(),
            'ownership_type' => $transaction->getInAppOwnershipType(),
        ], $transaction->getOfferType() !== null ? [
            // 1 introductory, 2 promotional, 3 offer code, 4 win-back.
            'type' => $transaction->getOfferType(),
            'identifier' => $transaction->getOfferIdentifier(),
            'discount_type' => $transaction->getOfferDiscountType(),
            'period' => $transaction->getOfferPeriod(),
        ] : null);

        $this->ensureStoreAccountToken();
        // A shared purchase keeps the holder's label: its notifications still
        // route through the token, and this account is reached by its row.
        if (! $heldElsewhere) {
            $this->labelAppStoreTransaction($transaction, $source);
        }

        return $this->extendEntitlement($expiresAt);
    }

    /**
     * Whether a transaction the client posted was bought for a different,
     * still-existing account.
     *
     * The iOS app labels every purchase with the account's
     * `store_account_token` (see [ensureStoreAccountToken]), so a verified
     * transaction carrying another live account's token was bought on that
     * account: the same Apple ID signed in to a second account on the device,
     * or a jwsRepresentation posted from elsewhere. It is refused so that one
     * subscription unlocks one account, which is what the token is for.
     *
     * A token no account holds is not refused. Sign-out and account deletion
     * null the token and the next launch issues a new one, so the user's own
     * subscription comes back labelled with a token nobody holds any more.
     * That is a restore, and [labelAppStoreTransaction] moves the label over.
     *
     * A module that shares purchases ([sharesSubscriptionAcrossAccounts])
     * records the transaction anyway; the answer then only says whether the
     * label is someone else's and must be left alone.
     */
    private function appStoreTransactionBelongsToAnotherAccount(AppStoreTransaction $transaction): bool
    {
        $token = strtoupper((string) $transaction->getAppAccountToken());
        if ($token === '' || $token === strtoupper((string) $this->store_account_token)) {
            return false;
        }

        // Tokens are stored upper-cased (see ensureStoreAccountToken); Apple echoes them lower-cased.
        $holder = static::query()->where('store_account_token', $token)->whereKeyNot($this->getKey())->first();
        if ($holder === null) {
            return false;
        }

        SubscriptionLog::warning($this->sharesSubscriptionAcrossAccounts()
            ? 'App Store: client posted a transaction labelled with another account\'s token; recorded on both (shared subscription).'
            : 'App Store: client posted a transaction labelled with another account\'s token; not recorded.', [
                'app_name' => $this->appStoreAppName(),
                'account_id' => $this->getKey(),
                'holder_account_id' => $holder->getKey(),
                'transaction_id' => $transaction->getTransactionId(),
                'store_subscription_id' => $transaction->getOriginalTransactionId(),
                'environment' => $transaction->getEnvironment()->value,
                'app_account_token' => $transaction->getAppAccountToken(),
            ]);

        return true;
    }

    /**
     * Make sure the transaction carries this account's token.
     *
     * The iOS app sets the token at purchase, so an in-app purchase arrives
     * labelled. Two kinds do not:
     *
     * - A purchase made outside the app — an offer code redeemed in the App
     *   Store, a promoted purchase from the product page — carries no token,
     *   because no client code of ours ran.
     * - A restore after sign-out or account deletion. Those null the token
     *   and the next launch issues a new one, so the user's own subscription
     *   comes back carrying a token no account holds. Only a client post may
     *   re-label it: a notification routed here by the subscription row
     *   leaves a label it did not set alone, whoever's it is.
     *
     * Both go through Apple's Set App Account Token endpoint, which applies
     * to the current renewal and every later one, so that subscription's
     * notifications then resolve through the token like any other. A Family
     * Sharing transaction is skipped: Apple rejects the call for it
     * (FamilyTransactionNotSupportedError), and the label belongs to the
     * purchaser anyway.
     */
    private function labelAppStoreTransaction(AppStoreTransaction $transaction, string $source): void
    {
        if ($transaction->getInAppOwnershipType() === self::APP_STORE_OWNERSHIP_FAMILY_SHARED) {
            return;
        }

        $token = strtoupper((string) $transaction->getAppAccountToken());
        if ($token === strtoupper((string) $this->store_account_token)) {
            return;
        }

        if ($token !== '' && $source !== 'client') {
            return;
        }

        $originalTransactionId = (string) $transaction->getOriginalTransactionId();
        if (! AppStoreLookup::isTransactionId($originalTransactionId)) {
            return;
        }

        try {
            $validator = AppStorePurchases::get($this->appStoreAppName(), $transaction->getEnvironment());
            if ($validator instanceof AppleAppStoreValidator) {
                $validator->setAppAccountToken($originalTransactionId, (string) $this->store_account_token);
            }
        } catch (Throwable $e) {
            SubscriptionLog::error('Failed to set the appAccountToken', [
                'app_name' => $this->appStoreAppName(),
                'account_id' => $this->getKey(),
                'transaction_id' => $transaction->getTransactionId(),
                'store_subscription_id' => $originalTransactionId,
                'environment' => $transaction->getEnvironment()->value,
                'app_account_token' => $this->store_account_token,
                'previous_app_account_token' => $transaction->getAppAccountToken(),
            ], $e);
        }
    }

    /**
     * Write a normalized Play purchase onto the account's Play row and carry
     * the expiry over to the account.
     *
     * `$authoritative` distinguishes the callers: a client-supplied token may
     * only ever extend the expiry, whereas a webhook or worker re-sync applies
     * whatever Play now reports. An authoritative re-sync may shorten, so it
     * reads the whole row set rather than Play's expiry alone; otherwise a
     * Play downgrade would revoke a live App Store subscription on the same
     * account.
     *
     * @param  array{expires_at: CarbonInterface, starts_at: CarbonInterface|null, order_id: string|null, product_id: string|null, environment: string, state: string|null, obfuscated_account_id: string|null, offer_phase: string|null, offer_id: string|null, base_plan_id: string|null}  $purchase
     */
    private function recordPlayPurchase(string $purchaseToken, array $purchase, ?string $source, bool $authoritative = false): ?CarbonInterface
    {
        $expiresAt = $purchase['expires_at'];

        if ($source === 'client' && $this->playPurchaseBelongsToAnotherAccount($purchaseToken, $purchase) && ! $this->sharesSubscriptionAcrossAccounts()) {
            return null;
        }

        [$previous, $row] = $this->withSubscriptionLock(fn (): array => $this->writeSubscriptionRow(StoreSubscription::STORE_PLAY, [
            'last_checked_at' => now(),
            'environment' => $purchase['environment'],
            // Play has no original-transaction concept: the purchase token
            // is the stable handle across renewals, so it takes that slot.
            'store_subscription_id' => $purchaseToken,
            'store_transaction_id' => $purchase['order_id'],
            'starts_at' => $purchase['starts_at'],
            'expires_at' => $expiresAt,
            'last_checked_by' => $source,
        ], $authoritative));

        $offerPhase = $purchase['offer_phase'] ?? null;

        $this->logSubscriptionWrite($this->playAppName(), $previous, $row, $source ?? 'webhook', [
            'product_id' => $purchase['product_id'],
            'state' => $purchase['state'],
            'base_plan_id' => $purchase['base_plan_id'] ?? null,
        ], in_array($offerPhase, [SubscriptionOfferPhase::FREE_TRIAL->value, SubscriptionOfferPhase::INTRODUCTORY_PRICE->value], true) ? [
            'phase' => $offerPhase,
            'identifier' => $purchase['offer_id'] ?? null,
        ] : null);

        $this->ensureStoreAccountToken();

        if (! $authoritative) {
            return $this->extendEntitlement($expiresAt);
        }

        return $this->withSubscriptionLock(function (): ?CarbonInterface {
            $target = $this->latestSubscriptionEndFromRows();
            if ($target === null) {
                return null;
            }

            if ($this->subscription_end !== null && $target->equalTo($this->subscription_end)) {
                $this->touch();
                $this->subscriptionChanged();

                return null;
            }

            $this->update(['subscription_end' => $target]);
            $this->subscriptionChanged();

            return $target;
        });
    }

    /**
     * Whether a purchase the client posted was bought for a different,
     * still-existing account: the Play side of
     * [appStoreTransactionBelongsToAnotherAccount].
     *
     * The Android app sets BillingFlowParams.obfuscatedAccountId to the
     * account's `store_account_token` and Play echoes it back on the
     * purchase, and Google's guidance is to check that mapping on the backend
     * before granting. The app re-posts everything `queryPurchases` returns
     * on every launch, so on a shared device a second account signed in
     * under the same Google account posts the first account's token; it is
     * refused so that one subscription unlocks one account, unless the
     * module shares purchases ([sharesSubscriptionAcrossAccounts]).
     *
     * A purchase carrying no id (made outside the app, or on a build that
     * did not label) is granted to the posting account, as Google suggests.
     * One whose id no account holds is the user's own restore after sign-out
     * or deletion, which null the token. Play has no Set App Account Token
     * equivalent, so the stale id stays on the purchase for good; that is
     * harmless, because the row written here routes its notifications from
     * now on and the id is only consulted when no row holds the token.
     *
     * @param  array{expires_at: CarbonInterface, starts_at: CarbonInterface|null, order_id: string|null, product_id: string|null, environment: string, state: string|null, obfuscated_account_id: string|null, offer_phase: string|null, offer_id: string|null, base_plan_id: string|null}  $purchase
     */
    private function playPurchaseBelongsToAnotherAccount(string $purchaseToken, array $purchase): bool
    {
        $token = strtoupper((string) $purchase['obfuscated_account_id']);
        if ($token === '' || $token === strtoupper((string) $this->store_account_token)) {
            return false;
        }

        // Tokens are stored upper-cased (see ensureStoreAccountToken); Play echoes them verbatim.
        $holder = static::query()->where('store_account_token', $token)->whereKeyNot($this->getKey())->first();
        if ($holder === null) {
            return false;
        }

        SubscriptionLog::warning($this->sharesSubscriptionAcrossAccounts()
            ? 'Play: client posted a purchase labelled with another account\'s token; recorded on both (shared subscription).'
            : 'Play: client posted a purchase labelled with another account\'s token; not recorded.', [
                'app_name' => $this->playAppName(),
                'account_id' => $this->getKey(),
                'holder_account_id' => $holder->getKey(),
                'store_subscription_id' => $purchaseToken,
                'store_transaction_id' => $purchase['order_id'],
                'environment' => $purchase['environment'],
                'obfuscated_account_id' => $purchase['obfuscated_account_id'],
            ]);

        return true;
    }

    /** The account's row for $store, as it is before a write. */
    private function subscriptionRowFor(string $store): ?StoreSubscription
    {
        /** @var StoreSubscription|null $row */
        $row = $this->subscriptions()->where('store', $store)->first();

        return $row;
    }

    /**
     * Run `$callback` in a transaction holding this account's row lock, with
     * `subscription_end` re-read under it.
     *
     * Every write to the entitlement or to the store rows goes through here,
     * so a webhook, the worker and a client post for one account apply one
     * after the other, each against what the previous one wrote, rather than
     * deciding on an expiry read before the other landed. No store call is
     * made while the lock is held.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withSubscriptionLock(callable $callback): mixed
    {
        return $this->getConnection()->transaction(function () use ($callback): mixed {
            $this->setAttribute('subscription_end', $this->newQueryWithoutScopes()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->value('subscription_end'));
            $this->syncOriginalAttribute('subscription_end');

            return $callback();
        });
    }

    /**
     * Write what the store reported onto the account's row for `$store`, and
     * return the row as it was before (null when there was none) and after.
     *
     * Keyed by (account_id, store), and `store` written explicitly: keyed on
     * the account alone, an App Store write would fill a row this account
     * already holds from a Play purchase with Apple identifiers while it kept
     * store='play', and the worker would hand Apple's id to Play forever.
     *
     * A non-authoritative write (a client post) for the subscription the row
     * already holds, carrying an earlier expiry (a stale or replayed
     * credential, or a restore burst landing after the renewal a webhook
     * recorded), only stamps the check and leaves the later state in place.
     * A post for a different `store_subscription_id` is applied: it is the
     * purchase the store now has for this account, and the row's old
     * identifiers (a refunded or lapsed subscription whose revoke never
     * reached us) must not keep the worker checking the wrong one. Authoritative writes
     * (webhook, RTDN and worker; for Play, the `$authoritative` re-syncs)
     * apply whatever the store reports, in either direction, so an expiry or
     * downgrade the store reports still shortens the row. Refunds and
     * revocations go through [revokeSubscriptionRow] and are not affected.
     *
     * Call it under [withSubscriptionLock].
     *
     * @param  array{last_checked_at: CarbonInterface, environment: string, store_subscription_id: string|null, store_transaction_id: string|null, starts_at: CarbonInterface|null, expires_at: CarbonInterface, last_checked_by: string|null}  $values
     * @return array{0: StoreSubscription|null, 1: StoreSubscription}
     */
    private function writeSubscriptionRow(string $store, array $values, bool $authoritative): array
    {
        $previous = $this->subscriptionRowFor($store);

        // Only for the same subscription: a credential for a different one
        // (a new original transaction / purchase token) is that store's
        // current purchase and replaces the row, whatever the old expiry.
        if (
            ! $authoritative
            && $previous?->expires_at !== null
            && $previous->store_subscription_id === $values['store_subscription_id']
            && $values['expires_at']->lessThan($previous->expires_at)
        ) {
            $previous->update([
                'last_checked_at' => $values['last_checked_at'],
                'last_checked_by' => $values['last_checked_by'],
            ]);

            return [$previous, $previous];
        }

        /** @var StoreSubscription $row */
        $row = $this->subscriptions()->updateOrCreate(['account_id' => $this->getKey(), 'store' => $store], $values);

        return [$previous, $row];
    }

    /**
     * Hand a subscription start, offer start or renewal to the module's
     * activity log, see [logSubscriptionActivity].
     *
     * Classified from how the store row moved, so the same transaction
     * arriving twice (the client's restore burst, the webhook after the
     * client post, the hourly re-verify) is logged once, on whichever write
     * landed first:
     *
     * - No row for this store yet, or a row for a different subscription,
     *   is a *start*: "Subscription started", or "Subscription offer
     *   started" when the transaction is on an offer (an App Store
     *   introductory, promotional, offer-code or win-back offer; a Play free
     *   trial or introductory price). An App Store transaction whose reason
     *   is RENEWAL is logged as a renewal even here: that is an account
     *   picking up a subscription that already runs, after a reinstall or
     *   on a second linked profile, not a new subscriber.
     * - A row for the same subscription whose transaction (Apple) or order
     *   (Play) changed and whose expiry moved out is a *renewal*.
     * - Anything else is a re-verify of a state already recorded and is not
     *   logged; the write itself is still visible on the row.
     *
     * @param  array<string, mixed>  $details  store-specific fields for the context
     * @param  array<string, mixed>|null  $offer  the offer the transaction is on, or null at full price
     */
    private function logSubscriptionWrite(string $appName, ?StoreSubscription $previous, StoreSubscription $row, string $source, array $details, ?array $offer): void
    {
        $firstRow = $previous === null || $previous->store_subscription_id !== $row->store_subscription_id;

        if ($firstRow) {
            $event = ($details['transaction_reason'] ?? null) === 'RENEWAL'
                ? 'subscription_renewed'
                : ($offer !== null ? 'subscription_offer_started' : 'subscription_started');
        } elseif ($previous->store_transaction_id !== $row->store_transaction_id
            && $row->expires_at !== null
            && ($previous->expires_at === null || $row->expires_at->greaterThan($previous->expires_at))) {
            $event = 'subscription_renewed';
        } else {
            return;
        }

        $this->logSubscriptionActivity($event, [
            'store_app' => $appName,
            'store' => $row->store,
            'source' => $source,
            'environment' => $row->environment,
            'first_row_for_account' => $firstRow,
            'store_subscription_id' => $row->store_subscription_id,
            'store_transaction_id' => $row->store_transaction_id,
            'previous_store_transaction_id' => $previous?->store_transaction_id,
            'starts_at' => $row->starts_at?->toIso8601String(),
            'expires_at' => $row->expires_at?->toIso8601String(),
            'previous_expires_at' => $previous?->expires_at?->toIso8601String(),
            'offer' => $offer,
            ...$details,
        ], $source === 'client' ? 'user' : 'system');
    }

    /**
     * Move the account's expiry forward to $expiresAt if that is later than
     * what it already has. Returns the new expiry, or null when nothing moved.
     */
    private function extendEntitlement(CarbonInterface $expiresAt): ?CarbonInterface
    {
        // Compared against the expiry as it is under the lock, not as this
        // model loaded it: a renewal recorded meanwhile must not be undone.
        return $this->withSubscriptionLock(function () use ($expiresAt): ?CarbonInterface {
            if ($this->subscription_end === null || $expiresAt->greaterThan($this->subscription_end)) {
                $this->update(['subscription_end' => $expiresAt]);
                $this->subscriptionChanged();

                return $expiresAt;
            }

            // Not extended, but still a subscription write: that is activity for
            // the retention sweep, so a subscriber who never opens the app is not
            // purged while holding a live receipt.
            $this->touch();
            $this->subscriptionChanged();

            return null;
        });
    }

    /**
     * Stamp the row holding $originalTransactionId as dead, then re-derive the
     * account's entitlement from whatever rows are left: an account can hold
     * one row per store, and an active subscription on the other store must
     * survive this one's refund.
     */
    private function revokeSubscriptionRow(string $originalTransactionId, string $source): void
    {
        $this->withSubscriptionLock(function () use ($originalTransactionId, $source): void {
            $this->subscriptions()
                ->where('store_subscription_id', $originalTransactionId)
                ->update([
                    'expires_at' => now(),
                    'last_checked_at' => now(),
                    'last_checked_by' => $source,
                ]);

            $latest = $this->latestSubscriptionEndFromRows();

            if ($latest === null || ! $latest->isFuture()) {
                $this->markSubscriptionExpired();

                return;
            }

            // fill()->touch() rather than update(): the recomputed expiry can equal
            // the one already stored, and a no-op update would not stamp updated_at.
            $this->fill(['subscription_end' => $latest])->touch();
            $this->subscriptionChanged();
        });
    }

    /**
     * The latest `expires_at` across the account's store rows, or null
     * when it has none.
     */
    private function latestSubscriptionEndFromRows(): ?CarbonInterface
    {
        $latest = $this->subscriptions()->max('expires_at');

        if ($latest instanceof \DateTimeInterface) {
            return Carbon::instance($latest);
        }

        return is_string($latest) && $latest !== '' ? Carbon::parse($latest) : null;
    }
}
