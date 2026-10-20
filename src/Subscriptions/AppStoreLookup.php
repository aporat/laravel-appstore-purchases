<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions;

use Aporat\AppStorePurchases\Facades\AppStorePurchases;
use Exception;
use InvalidArgumentException;
use ReceiptValidator\AppleAppStore\LastTransactionItem;
use ReceiptValidator\AppleAppStore\Transaction;
use ReceiptValidator\AppleAppStore\Validator as AppleAppStoreValidator;
use ReceiptValidator\Environment;

/**
 * App Store Server API look-ups and StoreKit 2 signed-transaction verification,
 * through the validator configured under an app's name.
 */
final class AppStoreLookup
{
    /**
     * Every subscription status Apple holds for the subscription that
     * $transactionId belongs to. Any transaction id of the subscription will
     * do: the App Store Server API resolves it to the original.
     *
     * @return array<LastTransactionItem>
     */
    public static function subscriptionStatuses(string $appName, string $environment, string $transactionId): array
    {
        // The id becomes part of the request path, and Apple's are digits.
        if (! self::isTransactionId($transactionId)) {
            return [];
        }

        // The environment goes through get(), not setEnvironment(): the manager
        // caches one validator per name, so mutating the shared instance leaks
        // a sandbox lookup into every later call in the same worker process.
        $validator = AppStorePurchases::get($appName, self::resolveEnvironment($environment));

        if (! $validator instanceof AppleAppStoreValidator) {
            return [];
        }

        try {
            return $validator->getAllSubscriptionStatuses($transactionId)->getAllLastTransactions();
        } catch (Exception $e) {
            SubscriptionLog::error('Failed to fetch subscription statuses', [
                'app_name' => $appName,
                'transaction_id' => $transactionId,
            ], $e);

            return [];
        }
    }

    /**
     * Whether $value has the shape of an App Store transaction id: digits
     * only. Anything else is refused before it is put in a request path.
     */
    public static function isTransactionId(string $value): bool
    {
        return preg_match('/\A[0-9]{1,32}\z/', $value) === 1;
    }

    /**
     * Verify a StoreKit 2 signed transaction (`jwsRepresentation`) offline.
     *
     * Returns null if the JWS is malformed, not signed by Apple, or belongs to
     * another bundle ID. The payload is only a snapshot from when it was signed
     * (it misses later refunds), so callers should take the original transaction
     * ID and environment from it and look up the current state with
     * {@see latestSubscription}, not grant entitlement from the payload itself.
     *
     * The validator is picked from the payload's own `environment` claim. That
     * claim is unverified at this point, but the signature covers it and the
     * validator rejects a payload whose environment doesn't match its own.
     */
    public static function verifySignedTransaction(string $appName, string $signedTransaction): ?Transaction
    {
        try {
            $validator = AppStorePurchases::get($appName, self::resolveEnvironment(self::unverifiedClaim($signedTransaction, 'environment')));

            if (! $validator instanceof AppleAppStoreValidator) {
                return null;
            }

            return $validator->verifySignedTransaction($signedTransaction);
        } catch (Exception $e) {
            // Client-supplied input, so a bad JWS is a warning, not a failure.
            SubscriptionLog::warning('Failed to verify signed transaction', [
                'app_name' => $appName,
            ], $e);

            return null;
        }
    }

    /**
     * Read one claim from a JWS payload without checking its signature.
     */
    private static function unverifiedClaim(string $jws, string $claim): ?string
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $value = is_array($payload) ? ($payload[$claim] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    /**
     * The subscription transaction with the latest expiry among the app's
     * configured subscription products, for the subscription $transactionId
     * belongs to. Null when Apple knows nothing, or nothing relevant.
     */
    public static function latestSubscription(string $appName, string $environment, string $transactionId): ?LastTransactionItem
    {
        $subscriptionProductIds = ValidatorConfig::products($appName);
        if ($subscriptionProductIds === []) {
            return null;
        }

        $latest = null;

        foreach (self::subscriptionStatuses($appName, $environment, $transactionId) as $item) {
            $transaction = $item->getTransactionInfo();
            if ($transaction === null) {
                continue;
            }

            if (! in_array($transaction->getProductId(), $subscriptionProductIds, true)) {
                continue;
            }

            $expires = $transaction->getExpiresDate();
            if ($expires === null) {
                continue;
            }

            $latestExpires = $latest?->getTransactionInfo()?->getExpiresDate();
            if ($latestExpires === null || $expires->isAfter($latestExpires)) {
                $latest = $item;
            }
        }

        return $latest;
    }

    /**
     * Map a stored or caller-supplied environment string onto the validator's
     * enum, defaulting to production for anything unrecognised.
     *
     * Case-insensitive on purpose. Subscription rows persist
     * `Environment::PRODUCTION->value`, which is the lowercase 'production',
     * while callers default to the literal 'Production'. A case-sensitive
     * comparison treats stored rows as non-production and points live
     * transactions at the sandbox endpoint, where they come back 404.
     */
    public static function resolveEnvironment(?string $environment): Environment
    {
        if ($environment === null || trim($environment) === '') {
            return Environment::PRODUCTION;
        }

        try {
            return Environment::fromString($environment);
        } catch (InvalidArgumentException) {
            return Environment::PRODUCTION;
        }
    }
}
