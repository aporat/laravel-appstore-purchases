<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures;

use Aporat\AppStorePurchases\Subscriptions\Contracts\StoreSubscriber;
use Aporat\AppStorePurchases\Subscriptions\ManagesStoreSubscriptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An account that holds subscriptions, with the hooks recorded so tests can
 * see what the layer told the app.
 *
 * @property int $id
 * @property string|null $store_account_token
 * @property Carbon|null $subscription_end
 */
final class TestAccount extends Model implements StoreSubscriber
{
    use ManagesStoreSubscriptions;

    /** @var list<array{event: string, context: array<string, mixed>, actor: string, account_id: int}> */
    public static array $activity = [];

    /** @var array<int, int> account id => how many times subscriptionChanged() ran */
    public static array $changed = [];

    public static bool $shared = false;

    protected $table = 'accounts';

    protected $guarded = [];

    protected $casts = [
        'subscription_end' => 'datetime',
    ];

    public static function reset(): void
    {
        self::$activity = [];
        self::$changed = [];
        self::$shared = false;
    }

    public function appStoreAppName(): string
    {
        return 'apple';
    }

    public function playAppName(): string
    {
        return 'play';
    }

    /**
     * @return HasMany<TestSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(TestSubscription::class, 'account_id');
    }

    protected function subscriptionChanged(): void
    {
        self::$changed[$this->id] = (self::$changed[$this->id] ?? 0) + 1;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function logSubscriptionActivity(string $event, array $context, string $actor): void
    {
        self::$activity[] = ['event' => $event, 'context' => $context, 'actor' => $actor, 'account_id' => $this->id];
    }

    protected function sharesSubscriptionAcrossAccounts(): bool
    {
        return self::$shared;
    }
}
