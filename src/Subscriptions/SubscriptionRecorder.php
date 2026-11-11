<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions;

use Aporat\AppStorePurchases\Subscriptions\Contracts\StoreSubscriber;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The shared body of a `POST .../subscription` endpoint.
 *
 * An Android app posts `store=play` with its purchase token; iOS clients
 * send an App Store credential (see {@see AppStoreCredentialResolver}) and no
 * store at all. Every post is verified with the store; concurrent posts for
 * one account apply in turn on its row lock (see ManagesStoreSubscriptions).
 *
 * Field names come from `appstore-purchases.subscriptions.request`.
 */
final class SubscriptionRecorder
{
    /** What older iOS apps posted as `store` before Apple's own name settled on `app_store`. */
    public const string LEGACY_APP_STORE_NAME = 'itunes';

    /** @var array<string, string> */
    private const array DEFAULT_FIELDS = [
        'store' => 'store',
        'purchase_token' => 'purchase_token',
        'signed_transaction' => 'signed_transaction',
        'app_receipt' => 'app_receipt',
    ];

    /**
     * Apply whatever credential the request carries to $account.
     *
     * Returns the expiry the entitlement now runs to when this post moved it
     * and the account is active, or null when there was nothing to verify or
     * the store reported nothing new. Either
     * way the account's own instance has been written through, so a
     * `refresh()` reflects the outcome.
     *
     * @param  bool  $acceptAppReceipt  Also accept a StoreKit 1 app receipt, for apps that still ship one.
     */
    public static function record(Model&StoreSubscriber $account, Request $request, bool $acceptAppReceipt = false): ?CarbonInterface
    {
        // `itunes` is the legacy name for `app_store`; older installs keep
        // posting it long after a release switches, so the alias stays.
        $store = $request->string(self::field('store'), StoreSubscription::STORE_APP_STORE)->trim()->lower()->value();
        if ($store === self::LEGACY_APP_STORE_NAME) {
            $store = StoreSubscription::STORE_APP_STORE;
        }

        if ($store === StoreSubscription::STORE_PLAY) {
            $purchaseToken = $request->string(self::field('purchase_token'))->trim()->value();
            if ($purchaseToken === '') {
                return null;
            }

            $extendedTo = $account->verifyPlaySubscription($purchaseToken);
        } else {
            $credential = AppStoreCredentialResolver::fromRequest($request, $account->appStoreAppName(), $acceptAppReceipt);
            if ($credential === null) {
                return null;
            }

            $extendedTo = $account->verifyAppStoreSubscription($credential->transactionId, $credential->environment);
        }

        if ($extendedTo === null || ! $account->isSubscriptionActive()) {
            return null;
        }

        return $extendedTo;
    }

    /**
     * The request field carrying $name, as configured.
     */
    public static function field(string $name): string
    {
        $configured = config("appstore-purchases.subscriptions.request.{$name}");

        return is_string($configured) && $configured !== '' ? $configured : (self::DEFAULT_FIELDS[$name] ?? $name);
    }
}
