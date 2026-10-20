<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Subscriptions;

use Aporat\AppStorePurchases\Subscriptions\StoreSubscription;
use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\StoreFixtures;
use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\TestAccount;
use Mockery;

/**
 * The App Store write path: one row per store, a client credential only
 * extends, a webhook applies either direction, labels follow the rules,
 * and a revoke re-derives the entitlement from what is left.
 */
final class AppStoreEntitlementTest extends SubscriptionsTestCase
{
    public function test_a_verified_transaction_creates_the_row_and_grants_the_entitlement(): void
    {
        $this->fakeAppleValidator();
        $account = $this->account();
        $expires = now()->addDays(30);

        $granted = $account->applyAppStoreTransaction(StoreFixtures::transaction($expires));

        self::assertNotNull($granted);
        self::assertSame($expires->getTimestamp(), $granted->getTimestamp());
        self::assertTrue($account->fresh()->isSubscriptionActive());

        $rows = $this->rows($account);
        self::assertCount(1, $rows);
        self::assertSame(StoreSubscription::STORE_APP_STORE, $rows[0]->store);
        self::assertSame(StoreFixtures::APPLE_ORIGINAL_ID, $rows[0]->store_subscription_id);
        self::assertSame('2000000000000042', $rows[0]->store_transaction_id);
        self::assertSame('production', $rows[0]->environment);
        self::assertSame('webhook', $rows[0]->last_checked_by);
        self::assertNotEmpty($account->fresh()->store_account_token, 'a token is issued on the first write');
        self::assertSame(1, TestAccount::$changed[$account->id] ?? 0);
    }

    public function test_a_transaction_for_another_product_is_ignored(): void
    {
        $this->fakeAppleValidator();
        $account = $this->account();

        self::assertNull($account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30), ['productId' => 'com.example.consumable'])));
        self::assertCount(0, $this->rows($account));
        self::assertFalse($account->fresh()->isSubscriptionActive());
    }

    public function test_a_client_credential_never_shortens_the_entitlement_or_the_row(): void
    {
        $this->fakeAppleValidator();
        $account = $this->account();

        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30)));
        $stale = $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(10), ['transactionId' => '2000000000000041']), 'client');

        self::assertNull($stale);
        $row = $this->rows($account)[0];
        self::assertTrue($row->expires_at->isAfter(now()->addDays(29)), 'the row kept the later expiry');
        self::assertSame('2000000000000042', $row->store_transaction_id);
        self::assertSame('client', $row->last_checked_by, 'but the check was stamped');
        self::assertTrue($account->fresh()->subscription_end->isAfter(now()->addDays(29)));
    }

    public function test_an_authoritative_write_shortens_the_row_but_the_entitlement_only_ever_moves_forward_here(): void
    {
        $this->fakeAppleValidator();
        $account = $this->account();

        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30)));
        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(10), ['transactionId' => '2000000000000043']), 'webhook');

        $row = $this->rows($account)[0];
        self::assertTrue($row->expires_at->isBefore(now()->addDays(11)), 'the webhook applied the earlier expiry to the row');
        self::assertSame('2000000000000043', $row->store_transaction_id);
    }

    public function test_a_renewal_moves_the_row_and_logs_once(): void
    {
        $this->fakeAppleValidator();
        $account = $this->account();

        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30)));
        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30))); // the client's replay of the same transaction
        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(60), ['transactionId' => '2000000000000043', 'transactionReason' => 'RENEWAL']));

        $events = array_column(TestAccount::$activity, 'event');
        self::assertSame(['subscription_started', 'subscription_renewed'], $events);
        self::assertSame('system', TestAccount::$activity[0]['actor']);
        self::assertTrue(TestAccount::$activity[0]['context']['first_row_for_account']);
        self::assertSame('2000000000000042', TestAccount::$activity[1]['context']['previous_store_transaction_id']);
    }

    public function test_an_offer_start_and_a_client_actor_are_told_apart(): void
    {
        $this->fakeAppleValidator();
        $account = $this->account();

        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(7), ['offerType' => '1', 'offerIdentifier' => 'intro']), 'client');

        self::assertSame('subscription_offer_started', TestAccount::$activity[0]['event']);
        self::assertSame('user', TestAccount::$activity[0]['actor']);
        self::assertSame(['type' => '1', 'identifier' => 'intro', 'discount_type' => null, 'period' => null], TestAccount::$activity[0]['context']['offer']);
    }

    public function test_a_transaction_made_outside_the_app_gets_the_account_token(): void
    {
        $validator = $this->fakeAppleValidator();
        $account = $this->account();

        $validator->shouldReceive('setAppAccountToken')
            ->once()
            ->with(StoreFixtures::APPLE_ORIGINAL_ID, Mockery::on(fn (string $token): bool => $token === $account->fresh()->store_account_token));

        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30)));
    }

    public function test_a_transaction_the_app_labelled_is_left_alone(): void
    {
        $validator = $this->fakeAppleValidator();
        $account = $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000001']);

        $validator->shouldNotReceive('setAppAccountToken');

        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30), ['appAccountToken' => 'abcdef12-0000-4000-8000-000000000001']));
        // Another account's token is not overwritten by a webhook either.
        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(31), ['appAccountToken' => 'abcdef12-0000-4000-8000-000000000002']));
    }

    public function test_a_family_shared_transaction_is_never_labelled(): void
    {
        $validator = $this->fakeAppleValidator();
        $validator->shouldNotReceive('setAppAccountToken');

        $this->account()->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30), ['inAppOwnershipType' => 'FAMILY_SHARED']), 'client');
    }

    public function test_a_client_post_labelled_with_another_live_accounts_token_is_refused(): void
    {
        $validator = $this->fakeAppleValidator();
        $owner = $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000001']);
        $other = $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000002']);

        $validator->shouldNotReceive('setAppAccountToken');

        self::assertNull($other->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30), ['appAccountToken' => 'abcdef12-0000-4000-8000-000000000001']), 'client'));

        self::assertCount(0, $this->rows($other));
        self::assertFalse($other->fresh()->isSubscriptionActive());
        self::assertFalse($owner->fresh()->isSubscriptionActive(), 'the owner is untouched too');
    }

    public function test_a_label_nobody_holds_is_a_restore_and_moves_to_the_poster(): void
    {
        $validator = $this->fakeAppleValidator();
        $restoring = $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000009']);

        $validator->shouldReceive('setAppAccountToken')->once()->with(StoreFixtures::APPLE_ORIGINAL_ID, 'ABCDEF12-0000-4000-8000-000000000009');

        self::assertNotNull($restoring->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30), ['appAccountToken' => 'abcdef12-0000-4000-8000-000000000001']), 'client'));
    }

    public function test_a_shared_subscription_is_recorded_on_the_poster_and_keeps_the_holders_label(): void
    {
        TestAccount::$shared = true;
        $validator = $this->fakeAppleValidator();
        $owner = $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000001']);
        $other = $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000002']);

        $validator->shouldNotReceive('setAppAccountToken');

        self::assertNotNull($other->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30), ['appAccountToken' => 'abcdef12-0000-4000-8000-000000000001']), 'client'));

        self::assertCount(1, $this->rows($other));
        self::assertTrue($other->fresh()->isSubscriptionActive());
        self::assertFalse($owner->fresh()->isSubscriptionActive(), 'the holder only gains when its own post or a webhook arrives');
    }

    public function test_verify_looks_the_subscription_up_and_takes_the_latest_subscription_product(): void
    {
        $validator = $this->fakeAppleValidator();
        $account = $this->account();

        $validator->shouldReceive('getAllSubscriptionStatuses')->once()->with('2000000000000042')->andReturn(StoreFixtures::statusResponse(
            StoreFixtures::transaction(now()->addDays(5), ['productId' => 'com.example.consumable', 'transactionId' => '1']),
            StoreFixtures::transaction(now()->addDays(30), ['transactionId' => '2000000000000044']),
            StoreFixtures::transaction(now()->addDays(20), ['productId' => 'com.example.plan.yearly', 'transactionId' => '2000000000000045']),
        ));

        $granted = $account->verifyAppStoreSubscription('2000000000000042', 'Production');

        self::assertNotNull($granted);
        self::assertSame('2000000000000044', $this->rows($account)[0]->store_transaction_id);
        self::assertSame('client', $this->rows($account)[0]->last_checked_by);
    }

    public function test_verify_refuses_an_id_that_is_not_digits_before_calling_apple(): void
    {
        $validator = $this->fakeAppleValidator();
        $validator->shouldNotReceive('getAllSubscriptionStatuses');

        self::assertNull($this->account()->verifyAppStoreSubscription('../admin', 'Production'));
    }

    public function test_a_revoke_expires_the_row_and_falls_back_to_the_other_store(): void
    {
        $this->fakeAppleValidator();
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->andReturn(StoreFixtures::playPurchase(now()->addDays(15)));
        $account = $this->account();

        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30)));
        $account->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN);
        self::assertTrue($account->fresh()->subscription_end->isAfter(now()->addDays(29)));

        $account->revokeAppStoreSubscription(StoreFixtures::APPLE_ORIGINAL_ID);

        $rows = $this->rows($account);
        self::assertTrue($rows->firstWhere('store', 'app_store')->expires_at->isPast());
        self::assertTrue($account->fresh()->isSubscriptionActive(), 'the Play subscription survives');
        self::assertTrue($account->fresh()->subscription_end->isBefore(now()->addDays(16)), 'and now sets the entitlement');

        $account->revokePlaySubscription(StoreFixtures::PLAY_TOKEN);
        self::assertFalse($account->fresh()->isSubscriptionActive());
    }

    public function test_mark_expired_runs_the_change_hook(): void
    {
        $account = $this->account(['subscription_end' => now()->addDays(3)]);

        $account->markSubscriptionExpired();

        self::assertFalse($account->fresh()->isSubscriptionActive());
        self::assertSame(1, TestAccount::$changed[$account->id]);
    }

    public function test_every_write_touches_the_account_even_without_moving_the_expiry(): void
    {
        $this->fakeAppleValidator();
        $account = $this->account();
        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30)));

        TestAccount::query()->whereKey($account->id)->update(['updated_at' => now()->subDays(100)]);
        self::assertTrue($account->fresh()->updated_at->isBefore(now()->subDays(99)));

        $account->fresh()->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30)));

        self::assertTrue($account->fresh()->updated_at->isAfter(now()->subMinute()));
    }
}
