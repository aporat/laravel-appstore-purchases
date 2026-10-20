<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Subscriptions;

use Aporat\AppStorePurchases\Subscriptions\SubscriptionRecorder;
use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\StoreFixtures;
use Error;
use Illuminate\Http\Request;

final class SubscriptionRecorderTest extends SubscriptionsTestCase
{
    public function test_a_play_post_verifies_the_token_and_returns_the_expiry(): void
    {
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->once()->andReturn(StoreFixtures::playPurchase(now()->addDays(30)));
        $account = $this->account();

        $expiry = SubscriptionRecorder::record($account, $this->subscriptionPost(['store' => 'play', 'purchase_token' => StoreFixtures::PLAY_TOKEN]));

        self::assertNotNull($expiry);
        self::assertTrue($expiry->isAfter(now()->addDays(29)));
        self::assertTrue($account->fresh()->isSubscriptionActive());
    }

    public function test_a_signed_transaction_is_verified_offline_then_looked_up(): void
    {
        $validator = $this->fakeAppleValidator();
        $account = $this->account();

        $validator->shouldReceive('verifySignedTransaction')->once()->with('jws')->andReturn(StoreFixtures::transaction(now()->addDays(1)));
        $validator->shouldReceive('getAllSubscriptionStatuses')->once()->with(StoreFixtures::APPLE_ORIGINAL_ID)->andReturn(StoreFixtures::statusResponse(StoreFixtures::transaction(now()->addDays(30))));

        $expiry = SubscriptionRecorder::record($account, $this->subscriptionPost(['signed_transaction' => 'jws']));

        self::assertNotNull($expiry);
        self::assertTrue($expiry->isAfter(now()->addDays(29)), 'the entitlement comes from Apple, not the snapshot');
    }

    public function test_the_legacy_itunes_store_name_is_the_app_store(): void
    {
        $validator = $this->fakeAppleValidator();
        $validator->shouldReceive('verifySignedTransaction')->andReturn(StoreFixtures::transaction(now()->addDays(1)));
        $validator->shouldReceive('getAllSubscriptionStatuses')->andReturn(StoreFixtures::statusResponse(StoreFixtures::transaction(now()->addDays(30))));

        self::assertNotNull(SubscriptionRecorder::record($this->account(), $this->subscriptionPost(['store' => 'itunes', 'signed_transaction' => 'jws'])));
    }

    public function test_a_post_without_a_credential_does_nothing(): void
    {
        $this->fakeAppleValidator()->shouldNotReceive('verifySignedTransaction');

        self::assertNull(SubscriptionRecorder::record($this->account(), $this->subscriptionPost([])));
        self::assertNull(SubscriptionRecorder::record($this->account(), $this->subscriptionPost(['store' => 'play'])));
    }

    public function test_an_app_receipt_is_only_accepted_when_the_endpoint_opts_in(): void
    {
        $this->fakeAppleValidator()->shouldNotReceive('getAllSubscriptionStatuses');

        self::assertNull(SubscriptionRecorder::record($this->account(), $this->subscriptionPost(['app_receipt' => base64_encode('not a receipt')])));
        // Opted in, an unreadable receipt is refused quietly rather than thrown.
        self::assertNull(SubscriptionRecorder::record($this->account(), $this->subscriptionPost(['app_receipt' => base64_encode('not a receipt')]), acceptAppReceipt: true));
    }

    public function test_a_restore_burst_pays_for_one_store_call(): void
    {
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->once()->andReturn(StoreFixtures::playPurchase(now()->addDays(30)));
        $account = $this->account();

        self::assertNotNull(SubscriptionRecorder::record($account, $this->subscriptionPost(['store' => 'play', 'purchase_token' => StoreFixtures::PLAY_TOKEN])));
        self::assertNull(SubscriptionRecorder::record($account, $this->subscriptionPost(['store' => 'play', 'purchase_token' => StoreFixtures::PLAY_TOKEN])));
        self::assertNull(SubscriptionRecorder::record($account, $this->subscriptionPost(['store' => 'play', 'purchase_token' => StoreFixtures::PLAY_TOKEN])));
    }

    public function test_a_failed_verification_releases_the_dedupe_claim(): void
    {
        // The store lookups absorb their own API errors (they return null and
        // log), so only something unexpected escapes: an Error here stands in
        // for a database failure mid-write.
        $validator = $this->fakeAppleValidator();
        $validator->shouldReceive('verifySignedTransaction')->twice()->andThrow(new Error('boom'));
        $account = $this->account();

        foreach ([1, 2] as $attempt) {
            try {
                SubscriptionRecorder::record($account, $this->subscriptionPost(['signed_transaction' => 'jws']));
                self::fail('Expected the error to propagate');
            } catch (Error $e) {
                self::assertSame('boom', $e->getMessage(), "attempt {$attempt} reached the store");
            }
        }
    }

    public function test_the_dedupe_window_can_be_turned_off(): void
    {
        config(['appstore-purchases.subscriptions.dedupe_ttl' => 0]);
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->twice()->andReturn(StoreFixtures::playPurchase(now()->addDays(30)));
        $account = $this->account();

        SubscriptionRecorder::record($account, $this->subscriptionPost(['store' => 'play', 'purchase_token' => StoreFixtures::PLAY_TOKEN]));
        SubscriptionRecorder::record($account, $this->subscriptionPost(['store' => 'play', 'purchase_token' => StoreFixtures::PLAY_TOKEN]));
    }

    public function test_the_request_fields_are_configurable(): void
    {
        config(['appstore-purchases.subscriptions.request' => ['store' => 'shop', 'purchase_token' => 'token']]);
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->once()->andReturn(StoreFixtures::playPurchase(now()->addDays(30)));

        self::assertNotNull(SubscriptionRecorder::record($this->account(), $this->subscriptionPost(['shop' => 'play', 'token' => StoreFixtures::PLAY_TOKEN])));
        self::assertSame('signed_transaction', SubscriptionRecorder::field('signed_transaction'), 'unset fields keep their defaults');
    }

    public function test_nothing_is_returned_for_an_already_expired_subscription(): void
    {
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->andReturn(StoreFixtures::playPurchase(now()->subDay()));
        $account = $this->account();

        self::assertNull(SubscriptionRecorder::record($account, $this->subscriptionPost(['store' => 'play', 'purchase_token' => StoreFixtures::PLAY_TOKEN])));
        self::assertCount(1, $this->rows($account), 'the row is still written');
    }

    /**
     * @param  array<string, string>  $body
     */
    private function subscriptionPost(array $body): Request
    {
        return Request::create('/subscription', 'POST', $body);
    }
}
