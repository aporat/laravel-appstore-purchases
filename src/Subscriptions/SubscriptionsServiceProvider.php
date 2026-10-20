<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Subscriptions;

use Aporat\AppStorePurchases\Events;
use Aporat\AppStorePurchases\Events\GooglePlay as PlayEvents;
use Aporat\AppStorePurchases\Subscriptions\Listeners\AppStore;
use Aporat\AppStorePurchases\Subscriptions\Listeners\GooglePlay;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the subscription layer: the store-notification listeners that apply
 * App Store and Google Play events to the configured subscribers' accounts,
 * and the publishable migrations.
 *
 * Separate from the main provider, which is deferred: a deferred provider
 * only boots once one of its services is resolved, so it cannot register
 * event listeners. This one is eager and does nothing until
 * `appstore-purchases.subscribers` names at least one pair.
 */
final class SubscriptionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SubscriberRegistry::class, static fn (): SubscriberRegistry => new SubscriberRegistry);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../database/migrations' => $this->app->databasePath('migrations'),
        ], 'appstore-purchases-migrations');

        if ((new SubscriberRegistry)->isEmpty()) {
            return;
        }

        /** @var Dispatcher $events */
        $events = $this->app->make(Dispatcher::class);

        // App Store server notifications.
        $events->listen([
            Events\SubscriptionRenewed::class,
            Events\SubscriptionCreated::class,
            Events\SubscriptionRenewalChangedPref::class,
            Events\OfferRedeemed::class,
            Events\SubscriptionRenewalExtended::class,
            Events\PurchaseRefundReversed::class,
        ], AppStore\HandleSubscriptionRenewed::class);
        $events->listen(Events\SubscriptionExpired::class, AppStore\HandleSubscriptionExpired::class);
        $events->listen([Events\PurchaseRefunded::class, Events\PurchaseRevoked::class], AppStore\HandleSubscriptionRevoked::class);
        $events->listen(Events\Test::class, AppStore\HandleTest::class);

        // Google Play Real-time Developer Notifications.
        $events->listen([
            PlayEvents\SubscriptionRecovered::class,
            PlayEvents\SubscriptionRenewed::class,
            PlayEvents\SubscriptionCanceled::class,
            PlayEvents\SubscriptionPurchased::class,
            PlayEvents\SubscriptionOnHold::class,
            PlayEvents\SubscriptionInGracePeriod::class,
            PlayEvents\SubscriptionRestarted::class,
            PlayEvents\SubscriptionPriceChangeConfirmed::class,
            PlayEvents\SubscriptionDeferred::class,
            PlayEvents\SubscriptionPaused::class,
            PlayEvents\SubscriptionPauseScheduleChanged::class,
            PlayEvents\SubscriptionRevoked::class,
            PlayEvents\SubscriptionExpired::class,
            PlayEvents\SubscriptionItemsChanged::class,
            PlayEvents\SubscriptionCancellationScheduled::class,
            PlayEvents\SubscriptionPriceChangeUpdated::class,
            PlayEvents\SubscriptionPendingPurchaseCanceled::class,
            PlayEvents\SubscriptionPriceStepUpConsentUpdated::class,
            PlayEvents\SubscriptionUnknown::class,
        ], GooglePlay\HandleSubscriptionNotification::class);
        $events->listen(PlayEvents\PurchaseVoided::class, GooglePlay\HandlePurchaseVoided::class);
        $events->listen(PlayEvents\PendingRefundReview::class, GooglePlay\HandlePendingRefundReview::class);
        $events->listen([
            PlayEvents\OneTimeProductPurchased::class,
            PlayEvents\OneTimeProductCanceled::class,
            PlayEvents\OneTimeProductUnknown::class,
        ], GooglePlay\HandleOneTimeProductNotification::class);
        $events->listen(PlayEvents\Test::class, GooglePlay\HandleTest::class);
    }
}
