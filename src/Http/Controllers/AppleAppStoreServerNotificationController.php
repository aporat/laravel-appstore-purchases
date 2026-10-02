<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Http\Controllers;

use Aporat\AppStorePurchases\AppStorePurchasesManager;
use Aporat\AppStorePurchases\Events\ConsumptionRequest;
use Aporat\AppStorePurchases\Events\ExternalPurchaseToken;
use Aporat\AppStorePurchases\Events\GracePeriodExpired;
use Aporat\AppStorePurchases\Events\OfferRedeemed;
use Aporat\AppStorePurchases\Events\OneTimeCharge;
use Aporat\AppStorePurchases\Events\PurchaseRefundDeclined;
use Aporat\AppStorePurchases\Events\PurchaseRefunded;
use Aporat\AppStorePurchases\Events\PurchaseRefundReversed;
use Aporat\AppStorePurchases\Events\PurchaseRevoked;
use Aporat\AppStorePurchases\Events\SubscriptionCreated;
use Aporat\AppStorePurchases\Events\SubscriptionExpired;
use Aporat\AppStorePurchases\Events\SubscriptionFailedToRenew;
use Aporat\AppStorePurchases\Events\SubscriptionPriceIncrease;
use Aporat\AppStorePurchases\Events\SubscriptionRenewalChanged;
use Aporat\AppStorePurchases\Events\SubscriptionRenewalChangedPref;
use Aporat\AppStorePurchases\Events\SubscriptionRenewalExtended;
use Aporat\AppStorePurchases\Events\SubscriptionRenewalExtension;
use Aporat\AppStorePurchases\Events\SubscriptionRenewed;
use Aporat\AppStorePurchases\Events\Test;
use Aporat\AppStorePurchases\Logging\NotificationLogger;
use Aporat\AppStorePurchases\Support\NotificationReplayGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use LogicException;
use ReceiptValidator\AppleAppStore\ServerNotification as AppleAppStoreServerNotification;
use ReceiptValidator\AppleAppStore\ServerNotificationType as AppleAppStoreServerNotificationType;
use ReceiptValidator\AppleAppStore\Validator as AppleAppStoreValidator;
use ReceiptValidator\Exceptions\ValidationException;

/**
 * Receives App Store Server Notifications V2 and dispatches them as Laravel events.
 *
 * Every notification is verified against the Apple App Store validator
 * configured for its bundle ID: Apple's signature and certificate chain, the
 * bundle ID, the environment and, in production, the app Apple ID when one is
 * configured. A notification for a bundle ID with no validator entry is
 * rejected with 401, as is one that fails verification. Apple retries non-2xx
 * responses a handful of times and then gives up, so a foreign or forged
 * notification never reaches a listener.
 *
 * Sandbox and production notifications are both accepted for a configured
 * bundle ID, whichever environment the entry itself names: the validator is
 * resolved for the notification's environment, and the event exposes it.
 *
 * A notification whose UUID was already handled within the replay-protection
 * window is acknowledged with 204 and not dispatched again.
 *
 * @see https://developer.apple.com/documentation/appstoreservernotifications
 */
final class AppleAppStoreServerNotificationController
{
    /** The replay-guard source under which Apple notification UUIDs are recorded. */
    private const string REPLAY_SOURCE = 'apple';

    public function __construct(
        private readonly AppStorePurchasesManager $manager,
        private readonly NotificationLogger $logger,
        private readonly NotificationReplayGuard $replayGuard,
    ) {}

    public function __invoke(Request $request): Response
    {
        $payload = $request->all();

        // Decode once to learn which app and environment the notification
        // claims to belong to. This already checks Apple's signature; the
        // verifier below repeats that check, which is cheap, so that every
        // ownership rule lives in the library's verifyNotification().
        try {
            $notification = new AppleAppStoreServerNotification($payload);
        } catch (ValidationException $e) {
            if (self::isSignatureFailure($e)) {
                $this->logger->warning('Apple App Store server notification rejected: signature verification failed', [
                    'error' => $e->getMessage(),
                    'payload_size' => strlen((string) $request->getContent()),
                ]);

                return new Response(null, Response::HTTP_UNAUTHORIZED);
            }

            return $this->undecodable($request, $e);
        } catch (\Throwable $e) {
            return $this->undecodable($request, $e);
        }

        $bundleId = $notification->getBundleId();
        $name = $this->manager->appleAppStoreValidatorsByBundleId()[$bundleId] ?? null;

        if ($name === null) {
            $this->logger->warning('Apple App Store server notification rejected: no validator configured for bundle ID', [
                'bundle_id' => $bundleId,
                'environment' => $notification->getEnvironment()->value,
                'notification_type' => $notification->getNotificationType()->value,
                'notification_uuid' => $notification->getNotificationUUID(),
            ]);

            return new Response(null, Response::HTTP_UNAUTHORIZED);
        }

        $validator = $this->manager->get($name, $notification->getEnvironment());

        if (! $validator instanceof AppleAppStoreValidator) {
            throw new LogicException("Validator [{$name}] is not an Apple App Store validator."); // @codeCoverageIgnore
        }

        try {
            $notification = $validator->verifyNotification($payload);
        } catch (ValidationException $e) {
            $this->logger->warning('Apple App Store server notification rejected: verification failed', [
                'error' => $e->getMessage(),
                'validator' => $name,
                'bundle_id' => $bundleId,
                'environment' => $notification->getEnvironment()->value,
                'notification_type' => $notification->getNotificationType()->value,
                'notification_uuid' => $notification->getNotificationUUID(),
            ]);

            return new Response(null, Response::HTTP_UNAUTHORIZED);
        }

        $uuid = $notification->getNotificationUUID();

        if (! $this->replayGuard->claim(self::REPLAY_SOURCE, $uuid)) {
            $this->logger->info('Apple App Store server notification already handled; acknowledging duplicate', [
                'bundle_id' => $bundleId,
                'environment' => $notification->getEnvironment()->value,
                'notification_type' => $notification->getNotificationType()->value,
                'notification_uuid' => $uuid,
            ]);

            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        $event = match ($notification->getNotificationType()) {
            AppleAppStoreServerNotificationType::CONSUMPTION_REQUEST => new ConsumptionRequest($notification),
            AppleAppStoreServerNotificationType::GRACE_PERIOD_EXPIRED => new GracePeriodExpired($notification),
            AppleAppStoreServerNotificationType::OFFER_REDEEMED => new OfferRedeemed($notification),
            AppleAppStoreServerNotificationType::REFUND_DECLINED => new PurchaseRefundDeclined($notification),
            AppleAppStoreServerNotificationType::REFUND_REVERSED => new PurchaseRefundReversed($notification),
            AppleAppStoreServerNotificationType::SUBSCRIBED => new SubscriptionCreated($notification),
            AppleAppStoreServerNotificationType::EXPIRED => new SubscriptionExpired($notification),
            AppleAppStoreServerNotificationType::DID_CHANGE_RENEWAL_STATUS => new SubscriptionRenewalChanged($notification),
            AppleAppStoreServerNotificationType::DID_RENEW => new SubscriptionRenewed($notification),
            AppleAppStoreServerNotificationType::TEST => new Test($notification),
            AppleAppStoreServerNotificationType::DID_FAIL_TO_RENEW => new SubscriptionFailedToRenew($notification),
            AppleAppStoreServerNotificationType::PRICE_INCREASE => new SubscriptionPriceIncrease($notification),
            AppleAppStoreServerNotificationType::REFUND => new PurchaseRefunded($notification),
            AppleAppStoreServerNotificationType::RENEWAL_EXTENDED => new SubscriptionRenewalExtended($notification),
            AppleAppStoreServerNotificationType::REVOKE => new PurchaseRevoked($notification),
            AppleAppStoreServerNotificationType::EXTERNAL_PURCHASE_TOKEN => new ExternalPurchaseToken($notification),
            AppleAppStoreServerNotificationType::ONE_TIME_CHARGE => new OneTimeCharge($notification),
            AppleAppStoreServerNotificationType::DID_CHANGE_RENEWAL_PREF => new SubscriptionRenewalChangedPref($notification),
            AppleAppStoreServerNotificationType::RENEWAL_EXTENSION => new SubscriptionRenewalExtension($notification),
            default => null,
        };

        if ($event === null) {
            $this->logger->warning('Apple App Store server notification type has no mapped event', [
                'notification_type' => $notification->getNotificationType()->value,
                'notification_uuid' => $notification->getNotificationUUID(),
            ]);
        } else {
            try {
                event($event);
            } catch (\Throwable $e) {
                $this->logger->error('Apple App Store server notification listener threw an exception', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'notification_type' => $notification->getNotificationType()->value,
                    'notification_uuid' => $uuid,
                ]);

                // Apple retries on 500 with the same UUID; that retry must be
                // processed, not acknowledged as a duplicate.
                $this->replayGuard->release(self::REPLAY_SOURCE, $uuid);

                return new Response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function undecodable(Request $request, \Throwable $e): Response
    {
        $this->logger->error('Failed to decode Apple App Store server notification payload', [
            'error' => $e->getMessage(),
            'payload_size' => strlen((string) $request->getContent()),
        ]);

        return new Response(null, Response::HTTP_BAD_REQUEST);
    }

    /**
     * Whether the library rejected the payload because Apple's signature or
     * certificate chain did not verify, as opposed to it being malformed.
     * The library signals both with ValidationException; only the message
     * tells them apart.
     */
    private static function isSignatureFailure(ValidationException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'signature');
    }
}
