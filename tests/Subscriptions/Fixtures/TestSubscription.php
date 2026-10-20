<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures;

use Aporat\AppStorePurchases\Subscriptions\StoreSubscription;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read TestAccount|null $account
 */
final class TestSubscription extends StoreSubscription
{
    /**
     * @return BelongsTo<TestAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(TestAccount::class, 'account_id');
    }
}
