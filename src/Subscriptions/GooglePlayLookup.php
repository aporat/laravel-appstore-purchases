<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions;

use Aporat\AppStorePurchases\Facades\AppStorePurchases;
use Carbon\CarbonInterface;
use ReceiptValidator\GooglePlay\APIError;
use ReceiptValidator\GooglePlay\APIException;
use ReceiptValidator\GooglePlay\SubscriptionPurchase;
use ReceiptValidator\GooglePlay\Validator as GooglePlayValidator;
use Throwable;

/**
 * Google Play Billing counterpart to {@see AppStoreLookup}: the Android
 * Publisher `subscriptionsv2` call through the validator configured under
 * an app's name, reduced to the handful of fields the account needs.
 *
 * @phpstan-type PlayPurchase array{expires_at: CarbonInterface, starts_at: CarbonInterface|null, order_id: string|null, product_id: string|null, environment: string, state: string|null, obfuscated_account_id: string|null, offer_phase: string|null, offer_id: string|null, base_plan_id: string|null}
 */
final class GooglePlayLookup
{
    /**
     * Look up a subscription purchase and return its normalised details, or
     * null when the token is unknown, unverifiable, or not for a configured
     * subscription product.
     *
     * @return PlayPurchase|null
     */
    public static function subscription(string $appName, string $purchaseToken): ?array
    {
        try {
            $validator = AppStorePurchases::get($appName);

            if (! $validator instanceof GooglePlayValidator) {
                SubscriptionLog::warning('Play purchase lookup skipped: app is not configured with a Google Play validator.', [
                    'app_name' => $appName,
                ]);

                return null;
            }

            return self::normalise($appName, $validator->getSubscriptionPurchaseV2($purchaseToken));
        } catch (APIException $e) {
            // The token is client input (the app's purchase post, or a stored
            // row the worker re-checks), so Google rejecting the token itself
            // is a warning, not a failure. Auth, quota and 5xx stay errors.
            $badToken = self::isBadToken($e);
            $context = [
                'app_name' => $appName,
                'status_code' => $e->getStatusCode(),
                'reason' => $e->getReason(),
                'retryable' => $e->isRetryable(),
            ];

            if ($badToken) {
                SubscriptionLog::warning('Play purchase lookup rejected: bad purchase token', $context, $e);
            } else {
                SubscriptionLog::error('Failed to fetch Play subscription', $context, $e);
            }

            return null;
        } catch (Throwable $e) {
            SubscriptionLog::error('Failed to fetch Play subscription', [
                'app_name' => $appName,
            ], $e);

            return null;
        }
    }

    /**
     * Whether Google rejected the purchase token itself, as opposed to the
     * request (auth, quota) or failing on its side.
     *
     * Google's `reason` is the precise signal when it is one the library
     * knows; otherwise the status decides. 400 (malformed), 404 (unknown) and
     * 410 (superseded or too old) all mean the token will never resolve.
     */
    private static function isBadToken(APIException $e): bool
    {
        $error = $e->getError();

        if ($error !== null) {
            return match ($error) {
                APIError::PURCHASE_TOKEN_DOES_NOT_MATCH_PACKAGE_NAME,
                APIError::PURCHASE_TOKEN_NO_LONGER_VALID,
                APIError::SUBSCRIPTION_PURCHASE_NO_LONGER_AVAILABLE,
                APIError::PRODUCT_NOT_OWNED_BY_USER,
                APIError::INVALID,
                APIError::REQUIRED,
                APIError::NOT_FOUND => true,
                default => false,
            };
        }

        return in_array($e->getStatusCode(), [400, 404, 410], true);
    }

    /**
     * The configured app a Play package name belongs to, so a Pub/Sub topic
     * shared by several apps still routes each notification correctly.
     */
    public static function appNameForPackage(string $packageName): ?string
    {
        return ValidatorConfig::playValidatorForPackage($packageName);
    }

    /**
     * Reduce a `subscriptionsv2` purchase to the fields the account needs. A
     * subscription can carry several line items; the furthest expiry wins,
     * matching how {@see AppStoreLookup::latestSubscription} picks the latest
     * transaction. With no products configured for the app, every line item
     * counts.
     *
     * @return PlayPurchase|null
     */
    private static function normalise(string $appName, SubscriptionPurchase $purchase): ?array
    {
        $subscriptionProductIds = ValidatorConfig::products($appName);

        $expiresAt = null;
        $productId = null;
        $winningItem = null;

        foreach ($purchase->getLineItems() as $lineItem) {
            $itemProductId = $lineItem->getProductId();

            if ($subscriptionProductIds !== [] && ! in_array($itemProductId, $subscriptionProductIds, true)) {
                continue;
            }

            $itemExpiry = $lineItem->getExpiryTime();

            if ($itemExpiry !== null && ($expiresAt === null || $itemExpiry->greaterThan($expiresAt))) {
                $expiresAt = $itemExpiry;
                $productId = $itemProductId;
                $winningItem = $lineItem;
            }
        }

        if ($expiresAt === null) {
            return null;
        }

        return [
            'expires_at' => $expiresAt,
            'starts_at' => $purchase->getStartTime(),
            'order_id' => $purchase->getLatestOrderId(),
            'product_id' => $productId,
            // A licence-tester purchase is the closest Play equivalent to the
            // App Store's Sandbox environment.
            'environment' => $purchase->isTestPurchase() ? 'Sandbox' : 'Production',
            // e.g. SUBSCRIPTION_STATE_ACTIVE / _CANCELED / _EXPIRED: Play's own
            // verdict on the purchase, which the RTDN listeners lean on rather
            // than inferring intent from the notification type alone.
            'state' => $purchase->getSubscriptionState()->value,
            // Whatever the app passed as BillingFlowParams.obfuscatedAccountId
            // (the account's store_account_token, mirroring Apple's
            // appAccountToken), so an RTDN can be routed to an account before
            // the client has posted the token.
            'obfuscated_account_id' => $purchase->getObfuscatedExternalAccountId(),
            // The offer the winning line item is on, for the start/renewal log:
            // FREE_TRIAL or INTRODUCTORY_PRICE means the user is on an offer,
            // BASE_PRICE that they pay full price.
            'offer_phase' => $winningItem?->getOfferPhase()->value,
            'offer_id' => $winningItem?->getOfferId(),
            'base_plan_id' => $winningItem?->getBasePlanId(),
        ];
    }
}
