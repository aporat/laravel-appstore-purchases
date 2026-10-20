<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions;

use Illuminate\Http\Request;
use ReceiptValidator\AppleAppStore\AppReceiptVerifier;
use ReceiptValidator\Environment;
use Throwable;

/**
 * Turns the App Store fields of a subscription post into an {@see AppStoreCredential}.
 *
 * Current clients send the StoreKit 2 transaction's jwsRepresentation. It is
 * verified offline and only its verified ids are used, so a client can't
 * claim someone else's original transaction id.
 *
 * A StoreKit 1 app can only post its app receipt. The receipt is the proof of
 * purchase, so it must verify (store-receipt-validator's AppReceiptVerifier:
 * PKCS #7 signature and a chain to the Apple Root CA it ships) and be for
 * the app's bundle id. Only then is its transaction id used to look the
 * subscription up, so a client can't claim a subscription by wrapping an id
 * it happens to know in a receipt-shaped blob. The receipt path is opt-in
 * per endpoint (`$acceptAppReceipt`) so apps that never shipped StoreKit 1
 * never accept one.
 *
 * The request field names come from `appstore-purchases.subscriptions.request`.
 */
final class AppStoreCredentialResolver
{
    public static function fromRequest(Request $request, string $appName, bool $acceptAppReceipt = false): ?AppStoreCredential
    {
        $signedTransaction = $request->string(SubscriptionRecorder::field('signed_transaction'))->trim()->value();
        if ($signedTransaction !== '') {
            return self::fromSignedTransaction($appName, $signedTransaction);
        }

        if (! $acceptAppReceipt) {
            return null;
        }

        $appReceipt = $request->string(SubscriptionRecorder::field('app_receipt'))->trim()->value();
        if ($appReceipt !== '') {
            return self::fromAppReceipt($appName, $appReceipt);
        }

        return null;
    }

    public static function fromSignedTransaction(string $appName, string $signedTransaction): ?AppStoreCredential
    {
        $transaction = AppStoreLookup::verifySignedTransaction($appName, $signedTransaction);
        if ($transaction === null) {
            return null;
        }

        $originalTransactionId = (string) $transaction->getOriginalTransactionId();
        if (! AppStoreLookup::isTransactionId($originalTransactionId)) {
            return null;
        }

        return new AppStoreCredential(
            $originalTransactionId,
            $transaction->getEnvironment()->value,
            AppStoreCredential::KIND_SIGNED_TRANSACTION,
        );
    }

    /**
     * StoreKit 1 receipts carry no environment; a shipped App Store build is
     * assumed, so production.
     */
    public static function fromAppReceipt(string $appName, string $appReceipt): ?AppStoreCredential
    {
        try {
            /** @var AppReceiptVerifier $verifier */
            $verifier = app(AppReceiptVerifier::class);
            $receipt = $verifier->verify($appReceipt);
        } catch (Throwable $e) {
            // Client-supplied input, so a receipt that is unreadable or not
            // Apple's is a warning, not a failure, and certainly not a reason
            // to touch any row.
            SubscriptionLog::warning('App receipt did not verify', [
                'app_name' => $appName,
            ], $e);

            return null;
        }

        $bundleId = ValidatorConfig::bundleId($appName);
        if ($bundleId === null || ! hash_equals($bundleId, $receipt->getBundleId())) {
            SubscriptionLog::warning('App receipt is for another app; ignored.', [
                'app_name' => $appName,
                'receipt_bundle_id' => $receipt->getBundleId(),
            ]);

            return null;
        }

        // Apple transaction ids are digits; anything else never reaches a URL.
        $transactionId = (string) $receipt->getTransactionId();
        if (! AppStoreLookup::isTransactionId($transactionId)) {
            return null;
        }

        return new AppStoreCredential(
            $transactionId,
            Environment::PRODUCTION->value,
            AppStoreCredential::KIND_APP_RECEIPT,
        );
    }
}
