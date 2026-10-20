<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions;

use Aporat\AppStorePurchases\Subscriptions\Contracts\StoreSubscriber;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The shared body of a `POST .../subscription` endpoint.
 *
 * An Android app posts `store=play` with its purchase token; iOS clients
 * send an App Store credential (see {@see AppStoreCredentialResolver}) and no
 * store at all. Apps call the endpoint on every purchase update and once per
 * signed-in launch, so the same credential is deduped for a minute: StoreKit
 * clients walk the transaction queue on launch and fire one POST per
 * historical purchase, each resolving to the same subscription. Only the
 * first of that burst pays for the store round-trip; its twins fall through
 * to the account row, which the first request has already updated.
 *
 * Field names and the dedupe window come from
 * `appstore-purchases.subscriptions`.
 */
final class SubscriptionRecorder
{
    /** The default for `subscriptions.dedupe_ttl`: a restore burst lands inside a second. */
    public const int DEFAULT_DEDUPE_TTL = 60;

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
     * and the account is active, or null when there was nothing to verify,
     * the credential was a replay, or the store reported nothing new. Either
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

            $fingerprint = StoreSubscription::STORE_PLAY.'|'.$purchaseToken;
            $apply = fn (): ?CarbonInterface => $account->verifyPlaySubscription($purchaseToken);
        } else {
            $credential = AppStoreCredentialResolver::fromRequest($request, $account->appStoreAppName(), $acceptAppReceipt);
            if ($credential === null) {
                return null;
            }

            $fingerprint = StoreSubscription::STORE_APP_STORE.'|'.$credential->transactionId;
            $apply = fn (): ?CarbonInterface => $account->verifyAppStoreSubscription($credential->transactionId, $credential->environment);
        }

        $dedupeKey = sprintf('appstore-purchases:subscription:%s:%s:%s', $account->getConnectionName() ?? 'default', $account->getKey(), hash('sha256', $fingerprint));
        $ttl = self::dedupeTtl();

        if ($ttl > 0 && ! Cache::add($dedupeKey, true, $ttl)) {
            return null;
        }

        try {
            $extendedTo = $apply();
        } catch (Throwable $e) {
            // The verification never completed, so this credential was not
            // really handled. Drop the claim so a genuine retry isn't swallowed.
            Cache::forget($dedupeKey);

            throw $e;
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

    private static function dedupeTtl(): int
    {
        $ttl = config('appstore-purchases.subscriptions.dedupe_ttl', self::DEFAULT_DEDUPE_TTL);

        return is_numeric($ttl) ? max(0, (int) $ttl) : self::DEFAULT_DEDUPE_TTL;
    }
}
