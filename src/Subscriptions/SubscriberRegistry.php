<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions;

use Aporat\AppStorePurchases\Subscriptions\Contracts\StoreSubscriber;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The Account + Subscription model pairs the store webhook listeners search,
 * from `appstore-purchases.subscribers`:
 *
 *     'subscribers' => [
 *         ['account' => App\Models\Account::class, 'subscription' => App\Models\Subscription::class, 'play_validator' => 'google-play'],
 *     ],
 *
 * Each pair may live on its own database connection; a notification is
 * resolved by searching every pair, and the Play side narrows to one pair
 * through the validator its package name belongs to.
 *
 * @phpstan-type Subscriber array{account: class-string<Model&StoreSubscriber>, subscription: class-string<StoreSubscription>, play_validator: string|null}
 */
final class SubscriberRegistry
{
    /**
     * @return list<Subscriber>
     */
    public function all(): array
    {
        $configured = config('appstore-purchases.subscribers', []);

        if (! is_array($configured)) {
            return [];
        }

        $subscribers = [];

        foreach ($configured as $index => $entry) {
            if (! is_array($entry)) {
                throw new InvalidArgumentException("appstore-purchases.subscribers.{$index} must be an array.");
            }

            $account = $entry['account'] ?? null;
            $subscription = $entry['subscription'] ?? null;
            $playValidator = $entry['play_validator'] ?? null;

            if (! is_string($account) || ! is_subclass_of($account, Model::class) || ! is_subclass_of($account, StoreSubscriber::class)) {
                throw new InvalidArgumentException("appstore-purchases.subscribers.{$index}.account must be an Eloquent model implementing StoreSubscriber.");
            }

            if (! is_string($subscription) || ! is_subclass_of($subscription, StoreSubscription::class)) {
                throw new InvalidArgumentException("appstore-purchases.subscribers.{$index}.subscription must extend StoreSubscription.");
            }

            /** @var class-string<Model&StoreSubscriber> $account */
            /** @var class-string<StoreSubscription> $subscription */
            $subscribers[] = [
                'account' => $account,
                'subscription' => $subscription,
                'play_validator' => is_string($playValidator) && $playValidator !== '' ? $playValidator : null,
            ];
        }

        return $subscribers;
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    /**
     * The pair serving a Google Play validator entry.
     *
     * @return Subscriber|null
     */
    public function forPlayValidator(string $validatorName): ?array
    {
        foreach ($this->all() as $subscriber) {
            if ($subscriber['play_validator'] === $validatorName) {
                return $subscriber;
            }
        }

        return null;
    }

    /**
     * Every account, across every pair, whose `store_account_token` is
     * $storeAccountToken. Apple sends it back as the appAccountToken, Play as
     * the obfuscatedAccountId. A uuid is unique within a pair, but the same
     * user can hold one per product.
     *
     * @return list<Model&StoreSubscriber>
     */
    public function accountsByStoreAccountToken(?string $storeAccountToken): array
    {
        if ($storeAccountToken === null || $storeAccountToken === '') {
            return [];
        }

        $accounts = [];

        foreach ($this->all() as $subscriber) {
            foreach ($subscriber['account']::query()->where('store_account_token', strtoupper($storeAccountToken))->get() as $account) {
                /** @var Model&StoreSubscriber $account */
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    /**
     * Every account, across every pair, holding a subscription row for this
     * original transaction id (App Store) or purchase token (Play).
     *
     * @return list<Model&StoreSubscriber>
     */
    public function accountsByTransaction(string $storeSubscriptionId): array
    {
        $accounts = [];

        foreach ($this->all() as $subscriber) {
            foreach ($this->accountsHoldingRow($subscriber, $storeSubscriptionId) as $account) {
                $accounts[] = $account;
            }
        }

        return $accounts;
    }

    /**
     * The accounts of one pair holding a subscription row for $storeSubscriptionId.
     *
     * @param  Subscriber  $subscriber
     * @return list<Model&StoreSubscriber>
     */
    public function accountsHoldingRow(array $subscriber, string $storeSubscriptionId): array
    {
        $accountIds = $subscriber['subscription']::forStoreSubscriptionId($storeSubscriptionId)->pluck('account_id')->all();

        if ($accountIds === []) {
            return [];
        }

        $accounts = [];

        foreach ($subscriber['account']::query()->whereIn('id', $accountIds)->get() as $account) {
            /** @var Model&StoreSubscriber $account */
            $accounts[] = $account;
        }

        return $accounts;
    }

    /**
     * How the App Store listeners find their accounts: the holder of the
     * appAccountToken, plus every account holding a row for the original
     * transaction id. The union, not a fallback: an app that shares one
     * purchase across accounts has rows on accounts the token does not
     * name, and a renewal or revoke must reach them too.
     *
     * @return array{0: list<Model&StoreSubscriber>, 1: bool} the accounts, and whether the token matched
     */
    public function resolveAppStoreAccounts(?string $appAccountToken, string $originalTransactionId): array
    {
        $byToken = $this->accountsByStoreAccountToken($appAccountToken);

        $accounts = $byToken;
        foreach ($this->accountsByTransaction($originalTransactionId) as $account) {
            foreach ($accounts as $known) {
                if ($known::class === $account::class && $known->getKey() === $account->getKey()) {
                    continue 2;
                }
            }
            $accounts[] = $account;
        }

        return [$accounts, $byToken !== []];
    }
}
