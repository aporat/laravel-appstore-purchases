<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions;

/**
 * What a client's App Store post boils down to once its input has been
 * checked: a transaction id to look the subscription up by, and the
 * environment to look it up in.
 */
final readonly class AppStoreCredential
{
    public const string KIND_SIGNED_TRANSACTION = 'signed_transaction';

    public const string KIND_APP_RECEIPT = 'app_receipt';

    public function __construct(
        public string $transactionId,
        public string $environment,
        public string $kind,
    ) {}
}
