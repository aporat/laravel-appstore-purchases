<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Http\Controllers;

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
use Aporat\AppStorePurchases\Logging\NotificationLogger;
use Aporat\AppStorePurchases\Support\NotificationReplayGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ReceiptValidator\GooglePlay\OneTimeProductNotificationType;
use ReceiptValidator\GooglePlay\ServerNotification;
use ReceiptValidator\GooglePlay\SubscriptionNotificationType;

/**
 * Receives Google Play Real-time Developer Notifications via a Cloud Pub/Sub
 * push subscription and dispatches them as Laravel events.
 *
 * Response codes follow Pub/Sub's retry semantics: any non-2xx is redelivered
 * for up to seven days. A payload that cannot be decoded will never become
 * decodable, so it is acknowledged with a 200 and logged. A listener failure
 * returns 500 so the message is retried. Only a failed push verification answers 401.
 * A message whose ID was already handled within the replay-protection window
 * is acknowledged with 200 and not dispatched again.
 *
 * @see https://developer.android.com/google/play/billing/rtdn-reference
 */
final class GooglePlayServerNotificationController
{
    /** The replay-guard source under which Pub/Sub message IDs are recorded. */
    private const string REPLAY_SOURCE = 'google-play';

    public function __construct(
        private readonly PubSubPushVerifier $verifier,
        private readonly NotificationLogger $logger,
        private readonly NotificationReplayGuard $replayGuard,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->verifier->verify($request)) {
            return new JsonResponse(['error' => 'Unauthorized'], JsonResponse::HTTP_UNAUTHORIZED);
        }

        try {
            $notification = ServerNotification::fromPubSubMessage($request->all());
        } catch (\Throwable $e) {
            $this->logger->error('Failed to decode Google Play developer notification payload', [
                'error' => $e->getMessage(),
                'payload_size' => strlen((string) $request->getContent()),
            ]);

            return $this->ack('ignored: undecodable payload');
        }

        $messageId = self::messageIdFrom($request);

        if (! $this->replayGuard->claim(self::REPLAY_SOURCE, $messageId)) {
            $this->logger->info('Google Play developer notification already handled; acknowledging duplicate', [
                'message_id' => $messageId,
                'package_name' => $notification->getPackageName(),
                'purchase_token' => $notification->getPurchaseToken(),
            ]);

            return $this->ack('ignored: duplicate');
        }

        $event = $this->eventFor($notification);

        try {
            event($event);
        } catch (\Throwable $e) {
            $this->logger->error('Google Play developer notification listener threw an exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'event' => $event::class,
                'package_name' => $notification->getPackageName(),
                'purchase_token' => $notification->getPurchaseToken(),
            ]);

            // Pub/Sub redelivers on 500 with the same message ID; that retry
            // must be processed, not acknowledged as a duplicate.
            $this->replayGuard->release(self::REPLAY_SOURCE, $messageId);

            return new JsonResponse(null, JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->ack('handled');
    }

    /**
     * The Pub/Sub message ID from the push envelope (`message.messageId`, or
     * `message.message_id` in the older spelling), or an empty string when the
     * envelope carries none, in which case no replay check is possible.
     */
    private static function messageIdFrom(Request $request): string
    {
        $id = $request->input('message.messageId') ?? $request->input('message.message_id');

        return is_scalar($id) ? (string) $id : '';
    }

    private function eventFor(ServerNotification $notification): GooglePlayEvent
    {
        if ($notification->isTestNotification()) {
            return new Test($notification);
        }

        if ($notification->isVoidedPurchaseNotification()) {
            return new PurchaseVoided($notification);
        }

        if ($notification->isPendingRefundReviewNotification()) {
            return new PendingRefundReview($notification);
        }

        $oneTime = $notification->getOneTimeProductNotification();
        if ($oneTime !== null) {
            return match ($oneTime->getNotificationType()) {
                OneTimeProductNotificationType::PURCHASED => new OneTimeProductPurchased($notification),
                OneTimeProductNotificationType::CANCELED => new OneTimeProductCanceled($notification),
                OneTimeProductNotificationType::UNKNOWN => $this->unknown($notification, OneTimeProductUnknown::class, $oneTime->getRawNotificationType()),
            };
        }

        $subscription = $notification->getSubscriptionNotification();
        if ($subscription !== null) {
            return match ($subscription->getNotificationType()) {
                SubscriptionNotificationType::RECOVERED => new SubscriptionRecovered($notification),
                SubscriptionNotificationType::RENEWED => new SubscriptionRenewed($notification),
                SubscriptionNotificationType::CANCELED => new SubscriptionCanceled($notification),
                SubscriptionNotificationType::PURCHASED => new SubscriptionPurchased($notification),
                SubscriptionNotificationType::ON_HOLD => new SubscriptionOnHold($notification),
                SubscriptionNotificationType::IN_GRACE_PERIOD => new SubscriptionInGracePeriod($notification),
                SubscriptionNotificationType::RESTARTED => new SubscriptionRestarted($notification),
                SubscriptionNotificationType::PRICE_CHANGE_CONFIRMED => new SubscriptionPriceChangeConfirmed($notification),
                SubscriptionNotificationType::DEFERRED => new SubscriptionDeferred($notification),
                SubscriptionNotificationType::PAUSED => new SubscriptionPaused($notification),
                SubscriptionNotificationType::PAUSE_SCHEDULE_CHANGED => new SubscriptionPauseScheduleChanged($notification),
                SubscriptionNotificationType::REVOKED => new SubscriptionRevoked($notification),
                SubscriptionNotificationType::EXPIRED => new SubscriptionExpired($notification),
                SubscriptionNotificationType::ITEMS_CHANGED => new SubscriptionItemsChanged($notification),
                SubscriptionNotificationType::CANCELLATION_SCHEDULED => new SubscriptionCancellationScheduled($notification),
                SubscriptionNotificationType::PRICE_CHANGE_UPDATED => new SubscriptionPriceChangeUpdated($notification),
                SubscriptionNotificationType::PENDING_PURCHASE_CANCELED => new SubscriptionPendingPurchaseCanceled($notification),
                SubscriptionNotificationType::PRICE_STEP_UP_CONSENT_UPDATED => new SubscriptionPriceStepUpConsentUpdated($notification),
                SubscriptionNotificationType::UNKNOWN => $this->unknown($notification, SubscriptionUnknown::class, $subscription->getRawNotificationType()),
            };
        }

        // Unreachable: ServerNotification rejects payloads with none of the five sections.
        return new GooglePlayEvent($notification); // @codeCoverageIgnore
    }

    /**
     * @param  class-string<GooglePlayEvent>  $eventClass
     */
    private function unknown(ServerNotification $notification, string $eventClass, int $rawType): GooglePlayEvent
    {
        $this->logger->warning('Google Play developer notification type has no mapped event', [
            'notification_type' => $rawType,
            'package_name' => $notification->getPackageName(),
            'purchase_token' => $notification->getPurchaseToken(),
        ]);

        return new $eventClass($notification);
    }

    private function ack(string $status): JsonResponse
    {
        return new JsonResponse(['status' => $status]);
    }
}
