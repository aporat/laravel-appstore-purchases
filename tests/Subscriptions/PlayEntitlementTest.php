<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Subscriptions;

use Aporat\AppStorePurchases\Subscriptions\StoreSubscription;
use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\StoreFixtures;
use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\TestAccount;
use ReceiptValidator\GooglePlay\APIException;

/**
 * The Google Play write path: a client token only extends, a sync applies
 * whatever Play reports, the purchase token is the stable handle, and the
 * obfuscatedAccountId plays the appAccountToken's part.
 */
final class PlayEntitlementTest extends SubscriptionsTestCase
{
    public function test_a_client_token_is_looked_up_and_recorded(): void
    {
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->once()->with(StoreFixtures::PLAY_TOKEN)->andReturn(StoreFixtures::playPurchase(now()->addDays(30)));
        $account = $this->account();

        $granted = $account->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN);

        self::assertNotNull($granted);
        $row = $this->rows($account)[0];
        self::assertSame(StoreSubscription::STORE_PLAY, $row->store);
        self::assertSame(StoreFixtures::PLAY_TOKEN, $row->store_subscription_id, 'the token is the stable handle');
        self::assertSame('GPA.0000-0001', $row->store_transaction_id);
        self::assertSame('Production', $row->environment);
        self::assertSame('client', $row->last_checked_by);
        self::assertSame('subscription_started', TestAccount::$activity[0]['event']);
        self::assertSame('monthly', TestAccount::$activity[0]['context']['base_plan_id']);
        self::assertSame('SUBSCRIPTION_STATE_ACTIVE', TestAccount::$activity[0]['context']['state']);
    }

    public function test_a_free_trial_is_an_offer_start(): void
    {
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->andReturn(StoreFixtures::playPurchase(now()->addDays(7), [
            'lineItems' => [['offerDetails' => ['basePlanId' => 'monthly', 'offerId' => 'trial'], 'offerPhase' => ['freeTrial' => []]]],
        ]));

        $this->account()->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN);

        self::assertSame('subscription_offer_started', TestAccount::$activity[0]['event']);
        self::assertSame(['phase' => 'FREE_TRIAL', 'identifier' => 'trial'], TestAccount::$activity[0]['context']['offer']);
    }

    public function test_a_licence_tester_purchase_is_sandbox(): void
    {
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->andReturn(StoreFixtures::playPurchase(now()->addDays(30), ['testPurchase' => []]));
        $account = $this->account();

        $account->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN);

        self::assertSame('Sandbox', $this->rows($account)[0]->environment);
    }

    public function test_a_sync_applies_a_shortened_expiry_while_a_client_post_does_not(): void
    {
        $validator = $this->fakePlayValidator();
        $account = $this->account();

        $validator->shouldReceive('getSubscriptionPurchaseV2')->andReturn(
            StoreFixtures::playPurchase(now()->addDays(30)),
            StoreFixtures::playPurchase(now()->addDays(3)),
            StoreFixtures::playPurchase(now()->addDays(3)),
        );

        $account->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN);
        self::assertNull($account->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN), 'a client post with an earlier expiry changes nothing');
        self::assertTrue($account->fresh()->subscription_end->isAfter(now()->addDays(29)));

        $shortened = $account->syncPlaySubscription(StoreFixtures::PLAY_TOKEN);

        self::assertNotNull($shortened);
        self::assertTrue($account->fresh()->subscription_end->isBefore(now()->addDays(4)));
        self::assertSame('webhook', $this->rows($account)[0]->last_checked_by);
    }

    public function test_a_sync_never_lets_play_revoke_a_live_app_store_subscription(): void
    {
        $this->fakeAppleValidator();
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->andReturn(
            StoreFixtures::playPurchase(now()->addDays(30)),
            StoreFixtures::playPurchase(now()->subDay()),
        );
        $account = $this->account();

        $account->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN);
        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(60)));

        $account->syncPlaySubscription(StoreFixtures::PLAY_TOKEN);

        self::assertTrue($account->fresh()->subscription_end->isAfter(now()->addDays(59)), 'the entitlement follows the furthest row');
    }

    public function test_a_client_post_labelled_for_another_live_account_is_refused(): void
    {
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->andReturn(StoreFixtures::playPurchase(now()->addDays(30), [
            'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => 'ABCDEF12-0000-4000-8000-000000000001'],
        ]));
        $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000001']);
        $other = $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000002']);

        self::assertNull($other->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN));
        self::assertCount(0, $this->rows($other));
    }

    public function test_a_label_nobody_holds_or_no_label_is_granted_to_the_poster(): void
    {
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->andReturn(
            StoreFixtures::playPurchase(now()->addDays(30), ['externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => 'NOBODY-HOLDS-THIS']]),
            StoreFixtures::playPurchase(now()->addDays(30)),
        );

        self::assertNotNull($this->account()->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN));
        self::assertNotNull($this->account()->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN));
    }

    public function test_a_rejected_token_or_an_outage_yields_nothing(): void
    {
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->andThrow(new APIException('Invalid token', 400));

        self::assertNull($this->account()->verifyPlaySubscription('garbage'));
    }

    public function test_an_app_without_a_play_validator_yields_nothing(): void
    {
        config(['appstore-purchases.validators.play.validator' => 'apple-app-store']);
        $this->fakeAppleValidator(null);

        self::assertNull($this->account()->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN));
    }
}
