<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures;

use Aporat\AppStorePurchases\Subscriptions\Console\VerifyStoreSubscriptions;

/**
 * The thinnest possible subclass: the model, and nothing else.
 */
final class VerifyTestSubscriptions extends VerifyStoreSubscriptions
{
    protected $signature = 'test:verify-subscriptions';

    protected $description = 'Re-verify the test subscriptions.';

    protected function subscriptionModel(): string
    {
        return TestSubscription::class;
    }
}
