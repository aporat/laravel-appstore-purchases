<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Tests\GooglePlay;

use Aporat\AppStorePurchases\AppStorePurchasesServiceProvider;
use Aporat\AppStorePurchases\Contracts\PubSubPushVerifier;
use Aporat\AppStorePurchases\Events\GooglePlay\GooglePlayEvent;
use Aporat\AppStorePurchases\Events\GooglePlay\OneTimeProductCanceled;
use Aporat\AppStorePurchases\Events\GooglePlay\OneTimeProductPurchased;
use Aporat\AppStorePurchases\Events\GooglePlay\OneTimeProductUnknown;
use Aporat\AppStorePurchases\Events\GooglePlay\PendingRefundReview;
use Aporat\AppStorePurchases\Events\GooglePlay\PurchaseVoided;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionCanceled;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionCancellationScheduled;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionDeferred;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionExpired;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionInGracePeriod;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionItemsChanged;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionOnHold;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPaused;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPauseScheduleChanged;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPendingPurchaseCanceled;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPriceChangeConfirmed;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPriceChangeUpdated;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPriceStepUpConsentUpdated;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionPurchased;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionRecovered;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionRenewed;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionRestarted;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionRevoked;
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionUnknown;
use Aporat\AppStorePurchases\Events\GooglePlay\Test;
use Aporat\AppStorePurchases\Http\Controllers\GooglePlayServerNotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test as TestAttr;
use ReceiptValidator\GooglePlay\SubscriptionNotificationType;
use RuntimeException;

class GooglePlayServerNotificationControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->post('/google-play/notifications', GooglePlayServerNotificationController::class);
    }

    protected function getPackageProviders($app): array
    {
        return [AppStorePurchasesServiceProvider::class];
    }

    /**
     * @param  array<string, mixed>  $notification
     * @return array<string, mixed>
     */
    private function envelope(array $notification): array
    {
        return [
            'message' => [
                'data' => base64_encode((string) json_encode($notification)),
                'messageId' => '1234567890',
                'publishTime' => '2026-09-05T15:00:00.000Z',
            ],
            'subscription' => 'projects/test-project/subscriptions/rtdn-push',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $json = file_get_contents(__DIR__."/fixtures/{$name}.json");
        $this->assertNotFalse($json, 'Fixture missing or unreadable');

        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    #[TestAttr]
    public function it_dispatches_test_event_and_acks(): void
    {
        Event::fake([Test::class]);

        $this->postJson('/google-play/notifications', $this->envelope($this->fixture('rtdnTest')))
            ->assertOk()
            ->assertJson(['status' => 'handled']);

        Event::assertDispatched(Test::class, function (Test $event): bool {
            return $event->notification->getPackageName() === 'app.example'
                && $event->purchaseToken() === null;
        });
    }

    #[TestAttr]
    public function it_dispatches_subscription_renewed(): void
    {
        Event::fake([SubscriptionRenewed::class]);

        $this->postJson('/google-play/notifications', $this->envelope($this->fixture('rtdnSubscription')))->assertOk();

        Event::assertDispatched(SubscriptionRenewed::class, function (SubscriptionRenewed $event): bool {
            return $event->purchaseToken() === 'sub-token-123'
                && $event->notification->getSubscriptionNotification()?->getSubscriptionId() === 'app.example.subscription';
        });
    }

    /**
     * @return iterable<string, array{0: int, 1: class-string<GooglePlayEvent>}>
     */
    public static function subscriptionTypes(): iterable
    {
        yield 'recovered' => [1, SubscriptionRecovered::class];
        yield 'renewed' => [2, SubscriptionRenewed::class];
        yield 'canceled' => [3, SubscriptionCanceled::class];
        yield 'purchased' => [4, SubscriptionPurchased::class];
        yield 'on hold' => [5, SubscriptionOnHold::class];
        yield 'grace period' => [6, SubscriptionInGracePeriod::class];
        yield 'restarted' => [7, SubscriptionRestarted::class];
        yield 'price change confirmed' => [8, SubscriptionPriceChangeConfirmed::class];
        yield 'deferred' => [9, SubscriptionDeferred::class];
        yield 'paused' => [10, SubscriptionPaused::class];
        yield 'pause schedule changed' => [11, SubscriptionPauseScheduleChanged::class];
        yield 'revoked' => [12, SubscriptionRevoked::class];
        yield 'expired' => [13, SubscriptionExpired::class];
        yield 'items changed' => [17, SubscriptionItemsChanged::class];
        yield 'cancellation scheduled' => [18, SubscriptionCancellationScheduled::class];
        yield 'price change updated' => [19, SubscriptionPriceChangeUpdated::class];
        yield 'pending purchase canceled' => [20, SubscriptionPendingPurchaseCanceled::class];
        yield 'price step-up consent updated' => [22, SubscriptionPriceStepUpConsentUpdated::class];
    }

    #[TestAttr]
    public function it_covers_every_subscription_notification_type_the_validator_knows(): void
    {
        $covered = array_map(static fn (array $row): int => $row[0], iterator_to_array(self::subscriptionTypes()));
        $known = array_map(
            static fn (SubscriptionNotificationType $type): int => $type->value,
            array_filter(SubscriptionNotificationType::cases(), static fn (SubscriptionNotificationType $type): bool => $type !== SubscriptionNotificationType::UNKNOWN),
        );

        // A case added to the validator with no arm in the controller's match
        // throws UnhandledMatchError on the first real notification of that type.
        $this->assertSame([], array_values(array_diff($known, $covered)), 'Subscription notification types with no mapped event');
    }

    /**
     * @param  class-string<GooglePlayEvent>  $eventClass
     */
    #[TestAttr]
    #[DataProvider('subscriptionTypes')]
    public function it_maps_every_subscription_notification_type(int $type, string $eventClass): void
    {
        Event::fake();

        $notification = $this->fixture('rtdnSubscription');
        $notification['subscriptionNotification']['notificationType'] = $type;

        $this->postJson('/google-play/notifications', $this->envelope($notification))->assertOk();

        Event::assertDispatched($eventClass);
    }

    #[TestAttr]
    public function it_dispatches_unknown_subscription_type_with_warning(): void
    {
        Event::fake();
        Log::spy();

        $notification = $this->fixture('rtdnSubscription');
        $notification['subscriptionNotification']['notificationType'] = 99;

        $this->postJson('/google-play/notifications', $this->envelope($notification))->assertOk();

        Event::assertDispatched(SubscriptionUnknown::class);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'no mapped event')
                && ($context['notification_type'] ?? null) === 99);
    }

    #[TestAttr]
    public function it_maps_one_time_product_notifications(): void
    {
        Event::fake();

        $purchased = $this->fixture('rtdnOneTime');
        $this->postJson('/google-play/notifications', $this->envelope($purchased))->assertOk();
        Event::assertDispatched(OneTimeProductPurchased::class, fn (OneTimeProductPurchased $e): bool => $e->purchaseToken() === 'one-time-token-123');

        $canceled = $purchased;
        $canceled['oneTimeProductNotification']['notificationType'] = 2;
        $this->postJson('/google-play/notifications', $this->envelope($canceled))->assertOk();
        Event::assertDispatched(OneTimeProductCanceled::class);

        $unknown = $purchased;
        $unknown['oneTimeProductNotification']['notificationType'] = 7;
        $this->postJson('/google-play/notifications', $this->envelope($unknown))->assertOk();
        Event::assertDispatched(OneTimeProductUnknown::class);
    }

    #[TestAttr]
    public function it_dispatches_purchase_voided(): void
    {
        Event::fake([PurchaseVoided::class]);

        $this->postJson('/google-play/notifications', $this->envelope($this->fixture('rtdnVoided')))->assertOk();

        Event::assertDispatched(PurchaseVoided::class, function (PurchaseVoided $event): bool {
            return $event->notification->getVoidedPurchaseNotification()?->getOrderId() === 'GPA.1000-2000-3000-40000';
        });
    }

    #[TestAttr]
    public function it_dispatches_pending_refund_review(): void
    {
        Event::fake([PendingRefundReview::class]);

        $this->postJson('/google-play/notifications', $this->envelope($this->fixture('rtdnPendingRefundReview')))->assertOk();

        Event::assertDispatched(PendingRefundReview::class, function (PendingRefundReview $event): bool {
            $payload = $event->notification->getPendingRefundReviewNotification();

            return $payload?->getOrderId() === 'GPA.1234-5678-9012-34567'
                && $payload->getPendingRefundToken() === 'pending-refund-token-123';
        });
    }

    #[TestAttr]
    public function it_acks_undecodable_payloads_so_pubsub_stops_retrying(): void
    {
        Event::fake();
        Log::spy();

        $this->postJson('/google-play/notifications', ['message' => ['messageId' => '1']])
            ->assertOk()
            ->assertJson(['status' => 'ignored: undecodable payload']);

        Event::assertNotDispatched(fn (GooglePlayEvent $event): bool => true);
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'Failed to decode')
                && array_key_exists('payload_size', $context));
    }

    #[TestAttr]
    public function it_returns_500_when_event_listener_throws(): void
    {
        Event::listen(Test::class, function (): void {
            throw new RuntimeException('listener boom');
        });

        Log::spy();

        $this->postJson('/google-play/notifications', $this->envelope($this->fixture('rtdnTest')))->assertStatus(500);

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'listener threw an exception')
                && ($context['event'] ?? null) === Test::class
                && ($context['package_name'] ?? null) === 'app.example');
    }

    #[TestAttr]
    public function it_returns_401_when_push_verification_fails(): void
    {
        Event::fake();

        $this->app->instance(PubSubPushVerifier::class, new class implements PubSubPushVerifier
        {
            public function verify(Request $request): bool
            {
                return false;
            }
        });

        $this->postJson('/google-play/notifications', $this->envelope($this->fixture('rtdnTest')))->assertStatus(401);

        Event::assertNotDispatched(Test::class);
    }
}
