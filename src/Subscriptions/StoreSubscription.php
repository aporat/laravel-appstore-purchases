<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions;

use Aporat\AppStorePurchases\Subscriptions\Contracts\StoreSubscriber;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The `subscriptions` table kept next to an `accounts` table: one row per
 * (account, store), holding the latest state that store reported.
 *
 * `store_subscription_id` is the stable handle for the subscription: Apple's
 * original transaction id for App Store rows, the purchase token for Google
 * Play rows (Play has no original-transaction concept). Re-verification and
 * webhook routing key off it. `store_transaction_id` is the latest
 * transaction (Apple) or order id (Play) the store reported.
 *
 * Subclasses set the connection and declare the `account()` relation to
 * their own Account model. The schema is in database/migrations.
 *
 * @property int $id
 * @property int $account_id
 * @property string $store
 * @property string $environment
 * @property string|null $store_subscription_id
 * @property string|null $store_transaction_id
 * @property Carbon|null $last_checked_at
 * @property string|null $last_checked_by
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read (Model&StoreSubscriber)|null $account
 */
abstract class StoreSubscription extends Model
{
    public const string STORE_APP_STORE = 'app_store';

    public const string STORE_PLAY = 'play';

    /** The table associated with the model. */
    protected $table = 'subscriptions';

    /** The attributes that should be cast. */
    protected $casts = [
        'account_id' => 'integer',
        'environment' => 'string',
        'last_checked_at' => 'datetime',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** The attributes that are mass assignable. */
    protected $fillable = [
        'account_id',
        'store',
        'environment',
        'store_subscription_id',
        'store_transaction_id',
        'last_checked_at',
        'last_checked_by',
        'starts_at',
        'expires_at',
    ];

    /**
     * Every row holding this original transaction id or purchase token. One
     * Apple subscription can back many accounts (an app that keeps one
     * account per linked profile on a device), so this is deliberately
     * uncapped.
     *
     * @return Collection<int, static>
     */
    public static function forStoreSubscriptionId(string $storeSubscriptionId): Collection
    {
        return static::query()
            ->where('store_subscription_id', $storeSubscriptionId)
            ->orderBy('account_id')
            ->get();
    }
}
