<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\Subscriptions;

use Aporat\AppStorePurchases\Events;
use Aporat\AppStorePurchases\Events\GooglePlay as PlayEvents;
use Aporat\AppStorePurchases\Subscriptions\Listeners\AppStore\HandleSubscriptionRenewed;
use Aporat\AppStorePurchases\Subscriptions\SubscriptionsServiceProvider;
use Aporat\AppStorePurchases\Tests\Subscriptions\Fixtures\StoreFixtures;
use Illuminate\Support\Facades\Event;
use ReceiptValidator\AppleAppStore\ServerNotificationType;

/**
 * The store notifications reach the accounts through the registry: App
 * Store events by appAccountToken and original transaction id, Play events
 * by purchase token with the obfuscatedAccountId as the fallback.
 */
final class ListenersTest extends SubscriptionsTestCase
{
    public function test_the_listeners_are_registered_for_a_configured_subscriber(): void
    {
        self::assertTrue(Event::hasListeners(Events\SubscriptionRenewed::class));
        self::assertTrue(Event::hasListeners(Events\PurchaseRefunded::class));
        self::assertTrue(Event::hasListeners(PlayEvents\SubscriptionRenewed::class));
        self::assertTrue(Event::hasListeners(PlayEvents\PurchaseVoided::class));
        self::assertTrue(Event::hasListeners(PlayEvents\Test::class));
        self::assertContains(HandleSubscriptionRenewed::class, Event::getRawListeners()[Events\SubscriptionRenewed::class] ?? []);
    }

    public function test_an_app_store_renewal_reaches_the_holder_and_everyone_with_a_row(): void
    {
        $this->fakeAppleValidator();
        $holder = $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000001']);
        $sharer = $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000002']);
        $bystander = $this->account();
        $holder->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(1)));
        $sharer->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(1)));

        event(new Events\SubscriptionRenewed(StoreFixtures::appleNotification(StoreFixtures::transaction(now()->addDays(31), [
            'transactionId' => '2000000000000099',
            'appAccountToken' => 'abcdef12-0000-4000-8000-000000000001',
        ]))));

        self::assertTrue($holder->fresh()->subscription_end->isAfter(now()->addDays(30)));
        self::assertTrue($sharer->fresh()->subscription_end->isAfter(now()->addDays(30)));
        self::assertFalse($bystander->fresh()->isSubscriptionActive());
    }

    public function test_an_app_store_token_routes_a_first_purchase_before_any_row_exists(): void
    {
        $this->fakeAppleValidator();
        $account = $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000001']);

        event(new Events\SubscriptionCreated(StoreFixtures::appleNotification(StoreFixtures::transaction(now()->addDays(30), [
            'appAccountToken' => 'abcdef12-0000-4000-8000-000000000001',
        ]), ServerNotificationType::SUBSCRIBED)));

        self::assertTrue($account->fresh()->isSubscriptionActive());
    }

    public function test_an_expiry_a_refund_and_a_revoke_pull_the_entitlement(): void
    {
        $this->fakeAppleValidator();

        foreach ([Events\SubscriptionExpired::class, Events\PurchaseRefunded::class, Events\PurchaseRevoked::class] as $event) {
            $account = $this->account();
            $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30)));
            self::assertTrue($account->fresh()->isSubscriptionActive());

            event(new $event(StoreFixtures::appleNotification(StoreFixtures::transaction(now()->addDays(30)))));

            self::assertFalse($account->fresh()->isSubscriptionActive(), $event);
            self::assertTrue($this->rows($account)[0]->expires_at->isPast());
        }
    }

    public function test_a_notification_without_a_transaction_is_ignored(): void
    {
        $this->fakeAppleValidator();
        $account = $this->account();
        $account->applyAppStoreTransaction(StoreFixtures::transaction(now()->addDays(30)));

        event(new Events\SubscriptionExpired(StoreFixtures::appleNotification(null)));

        self::assertTrue($account->fresh()->isSubscriptionActive());
    }

    public function test_a_play_notification_re_reads_the_purchase_for_every_account_holding_the_token(): void
    {
        $validator = $this->fakePlayValidator();
        $account = $this->account();
        $validator->shouldReceive('getSubscriptionPurchaseV2')->andReturn(
            StoreFixtures::playPurchase(now()->addDays(1)),
            StoreFixtures::playPurchase(now()->addDays(31)),
        );
        $account->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN);

        event(new PlayEvents\SubscriptionRenewed(StoreFixtures::playSubscriptionNotification(2)));

        self::assertTrue($account->fresh()->subscription_end->isAfter(now()->addDays(30)));
        self::assertSame('webhook', $this->rows($account)[0]->last_checked_by);
    }

    public function test_a_play_revoke_pulls_the_entitlement_even_though_play_still_reports_an_expiry(): void
    {
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->andReturn(StoreFixtures::playPurchase(now()->addDays(30)));
        $account = $this->account();
        $account->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN);

        event(new PlayEvents\SubscriptionRevoked(StoreFixtures::playSubscriptionNotification(12)));

        self::assertFalse($account->fresh()->isSubscriptionActive());
    }

    public function test_a_voided_purchase_pulls_the_entitlement(): void
    {
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->andReturn(StoreFixtures::playPurchase(now()->addDays(30)));
        $account = $this->account();
        $account->verifyPlaySubscription(StoreFixtures::PLAY_TOKEN);

        event(new PlayEvents\PurchaseVoided(StoreFixtures::playNotification(['voidedPurchaseNotification' => [
            'purchaseToken' => StoreFixtures::PLAY_TOKEN,
            'orderId' => 'GPA.0000-0001',
            'productType' => 1,
            'refundType' => 1,
        ]])));

        self::assertFalse($account->fresh()->isSubscriptionActive());
    }

    public function test_a_first_play_purchase_is_routed_by_the_obfuscated_account_id(): void
    {
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->andReturn(StoreFixtures::playPurchase(now()->addDays(30), [
            'externalAccountIdentifiers' => ['obfuscatedExternalAccountId' => 'abcdef12-0000-4000-8000-000000000001'],
        ]));
        $account = $this->account(['store_account_token' => 'ABCDEF12-0000-4000-8000-000000000001']);

        event(new PlayEvents\SubscriptionPurchased(StoreFixtures::playSubscriptionNotification(4)));

        self::assertTrue($account->fresh()->isSubscriptionActive());
        self::assertSame(StoreFixtures::PLAY_TOKEN, $this->rows($account)[0]->store_subscription_id);
    }

    public function test_a_play_notification_for_an_unknown_package_or_token_changes_nothing(): void
    {
        $this->fakePlayValidator()->shouldReceive('getSubscriptionPurchaseV2')->andReturn(StoreFixtures::playPurchase(now()->addDays(30)));
        $account = $this->account();

        event(new PlayEvents\SubscriptionRenewed(StoreFixtures::playNotification(['subscriptionNotification' => ['notificationType' => 2, 'purchaseToken' => 'x']], 'com.someone.else')));
        event(new PlayEvents\SubscriptionRenewed(StoreFixtures::playSubscriptionNotification(2, 'token-nobody-holds')));

        self::assertFalse($account->fresh()->isSubscriptionActive());
    }

    public function test_nothing_is_registered_without_subscribers(): void
    {
        $this->app['config']->set('appstore-purchases.subscribers', []);
        Event::forget(Events\SubscriptionRenewed::class);

        (new SubscriptionsServiceProvider($this->app))->boot();

        self::assertFalse(Event::hasListeners(Events\SubscriptionRenewed::class));
    }
}
