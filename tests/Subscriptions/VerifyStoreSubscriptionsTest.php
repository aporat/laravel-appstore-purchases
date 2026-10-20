<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Subscriptions;

use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\StoreFixtures;
use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\TestSubscription;
use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\VerifyTestSubscriptions;
use Error;
use Illuminate\Contracts\Console\Kernel;
use ReceiptValidator\AppleAppStore\SubscriptionStatusResponse;
use ReceiptValidator\GooglePlay\APIException;

/**
 * The re-verify worker: rows near expiry go back to their store, failures
 * are stamped so they do not head every batch, orphans are dropped.
 */
final class VerifyStoreSubscriptionsTest extends SubscriptionsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand(new VerifyTestSubscriptions);
        config(['appstore-purchases.subscriptions.verification.min_sleep_microseconds' => 0, 'appstore-purchases.subscriptions.verification.max_sleep_microseconds' => 0]);
    }

    public function test_a_row_near_expiry_is_re_read_from_its_store(): void
    {
        $this->fakeAppleValidator()->shouldReceive('getAllSubscriptionStatuses')->once()->with(StoreFixtures::APPLE_ORIGINAL_ID)
            ->andReturn(StoreFixtures::statusResponse(StoreFixtures::transaction(now()->addDays(31), ['transactionId' => '2000000000000050'])));
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->once()->with(StoreFixtures::PLAY_TOKEN)
            ->andReturn(StoreFixtures::playPurchase(now()->addDays(2)));

        $apple = $this->account();
        $play = $this->account();
        TestSubscription::query()->create(['account_id' => $apple->id, 'store' => 'app_store', 'environment' => 'production', 'store_subscription_id' => StoreFixtures::APPLE_ORIGINAL_ID, 'expires_at' => now()->addDay()]);
        TestSubscription::query()->create(['account_id' => $play->id, 'store' => 'play', 'environment' => 'production', 'store_subscription_id' => StoreFixtures::PLAY_TOKEN, 'expires_at' => now()->addDays(2)]);
        $farOff = TestSubscription::query()->create(['account_id' => $this->account()->id, 'store' => 'app_store', 'environment' => 'production', 'store_subscription_id' => '9', 'expires_at' => now()->addDays(10)]);

        $this->artisan('test:verify-subscriptions')->assertSuccessful();

        self::assertTrue($apple->fresh()->subscription_end->isAfter(now()->addDays(30)));
        self::assertSame('worker', $this->rows($apple)[0]->last_checked_by);
        self::assertTrue($play->fresh()->subscription_end->isBefore(now()->addDays(3)), 'the Play re-sync is authoritative');
        self::assertNull($farOff->fresh()->last_checked_at, 'rows outside the window are not touched');
    }

    public function test_a_row_without_an_answer_is_stamped_and_a_failed_row_is_backdated(): void
    {
        // The Apple lookup absorbs Apple's errors (an empty answer, stamped as
        // checked); the second row's store call blows up outright.
        $this->fakeAppleValidator()->shouldReceive('getAllSubscriptionStatuses')->twice()->andReturnUsing(
            fn (): SubscriptionStatusResponse => StoreFixtures::statusResponse(),
            function (): never {
                throw new Error('database gone away');
            },
        );
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->once()->andThrow(new APIException('quota', 429));

        $silent = TestSubscription::query()->create(['account_id' => $this->account()->id, 'store' => 'app_store', 'environment' => 'production', 'store_subscription_id' => '1000000000000001', 'expires_at' => now()->addDay()]);
        $rejected = TestSubscription::query()->create(['account_id' => $this->account()->id, 'store' => 'play', 'environment' => 'production', 'store_subscription_id' => StoreFixtures::PLAY_TOKEN, 'expires_at' => now()->addDay()]);
        $failed = TestSubscription::query()->create(['account_id' => $this->account()->id, 'store' => 'app_store', 'environment' => 'production', 'store_subscription_id' => '1000000000000002', 'expires_at' => now()->addDay()]);

        $this->artisan('test:verify-subscriptions')->assertSuccessful();

        self::assertTrue($silent->fresh()->last_checked_at->isAfter(now()->subMinute()));
        self::assertSame('worker', $silent->fresh()->last_checked_by);
        self::assertTrue($rejected->fresh()->last_checked_at->isAfter(now()->subMinute()), 'a rejected token is an answer too');
        // 12h recheck, 1h retry: stamped as if checked 11 hours ago.
        self::assertEqualsWithDelta(now()->subHours(11)->getTimestamp(), $failed->fresh()->last_checked_at->getTimestamp(), 5);
    }

    public function test_an_orphaned_row_is_deleted_and_a_recently_checked_row_is_skipped(): void
    {
        $this->fakeAppleValidator()->shouldNotReceive('getAllSubscriptionStatuses');

        $orphan = TestSubscription::query()->create(['account_id' => 999, 'store' => 'app_store', 'environment' => 'production', 'store_subscription_id' => '1', 'expires_at' => now()->addDay()]);
        $recent = TestSubscription::query()->create(['account_id' => $this->account()->id, 'store' => 'app_store', 'environment' => 'production', 'store_subscription_id' => '2', 'expires_at' => now()->addDay(), 'last_checked_at' => now()->subHour()]);

        $this->artisan('test:verify-subscriptions')->assertSuccessful();

        self::assertNull($orphan->fresh());
        self::assertNotNull($recent->fresh());
    }

    public function test_an_apps_own_config_block_overrides_the_defaults(): void
    {
        config(['appstore-purchases.subscriptions.verification.check_within_days' => 0]);
        $this->fakeAppleValidator()->shouldNotReceive('getAllSubscriptionStatuses');

        TestSubscription::query()->create(['account_id' => $this->account()->id, 'store' => 'app_store', 'environment' => 'production', 'store_subscription_id' => '1', 'expires_at' => now()->addDay()]);

        $this->artisan('test:verify-subscriptions')->assertSuccessful();
    }
}
