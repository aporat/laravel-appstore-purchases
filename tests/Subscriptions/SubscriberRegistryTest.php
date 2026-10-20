<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Subscriptions;

use Aporat\AppStorePurchases\Subscriptions\SubscriberRegistry;
use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\StoreFixtures;
use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\TestAccount;
use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\TestSubscription;
use InvalidArgumentException;

final class SubscriberRegistryTest extends SubscriptionsTestCase
{
    public function test_it_reads_the_configured_pairs(): void
    {
        $registry = new SubscriberRegistry;

        self::assertFalse($registry->isEmpty());
        self::assertSame([['account' => TestAccount::class, 'subscription' => TestSubscription::class, 'play_validator' => 'play']], $registry->all());
        self::assertSame(TestAccount::class, $registry->forPlayValidator('play')['account'] ?? null);
        self::assertNull($registry->forPlayValidator('other'));
    }

    public function test_an_empty_or_missing_list_is_empty(): void
    {
        config(['appstore-purchases.subscribers' => []]);
        self::assertTrue((new SubscriberRegistry)->isEmpty());

        config(['appstore-purchases.subscribers' => null]);
        self::assertTrue((new SubscriberRegistry)->isEmpty());
    }

    public function test_a_pair_with_the_wrong_classes_is_refused(): void
    {
        config(['appstore-purchases.subscribers' => [['account' => TestSubscription::class, 'subscription' => TestSubscription::class]]]);

        $this->expectException(InvalidArgumentException::class);
        (new SubscriberRegistry)->all();
    }

    public function test_accounts_are_found_by_token_and_by_row_and_the_union_is_deduplicated(): void
    {
        $this->fakeAppleValidator();
        $registry = new SubscriberRegistry;
        $holder = $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000001']);
        $sharer = $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000002']);
        $this->account();

        $holder->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30)));
        $sharer->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30)));

        self::assertSame([$holder->id], array_map(fn ($a) => $a->getKey(), $registry->accountsByStoreAccountToken('abcdef12-0000-4000-8000-000000000001')));
        self::assertSame([$holder->id, $sharer->id], array_map(fn ($a) => $a->getKey(), $registry->accountsByTransaction(StoreFixtures::APPLE_ORIGINAL_ID)));
        self::assertSame([], $registry->accountsByStoreAccountToken(null));

        [$accounts, $matched] = $registry->resolveAppStoreAccounts('abcdef12-0000-4000-8000-000000000001', StoreFixtures::APPLE_ORIGINAL_ID);
        self::assertTrue($matched);
        self::assertSame([$holder->id, $sharer->id], array_map(fn ($a) => $a->getKey(), $accounts));

        [$accounts, $matched] = $registry->resolveAppStoreAccounts(null, StoreFixtures::APPLE_ORIGINAL_ID);
        self::assertFalse($matched);
        self::assertCount(2, $accounts);
    }
}
