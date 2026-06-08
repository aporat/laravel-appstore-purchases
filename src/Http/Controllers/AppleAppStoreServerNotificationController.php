<?php

declare(strict_types=1);

namespace Aporat\AppStorePurchases\Http\Controllers;

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
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use ReceiptValidator\AppleAppStore\ServerNotification as AppleAppStoreServerNotification;
use ReceiptValidator\AppleAppStore\ServerNotificationType as AppleAppStoreServerNotificationType;

final class AppleAppStoreServerNotificationController
{
    public function __invoke(Request $request): Response
    {
        try {
            $notification = new AppleAppStoreServerNotification($request->all());
        } catch (\Throwable $e) {
            Log::error('Failed to decode Apple App Store server notification payload', [
                'error' => $e->getMessage(),
                'payload_size' => strlen((string) $request->getContent()),
            ]);

            return new Response(null, Response::HTTP_BAD_REQUEST);
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
            Log::warning('Apple App Store server notification type has no mapped event', [
                'notification_type' => $notification->getNotificationType()->value,
                'notification_uuid' => $notification->getNotificationUUID(),
            ]);
        } else {
            try {
                event($event);
            } catch (\Throwable $e) {
                Log::error('Apple App Store server notification listener threw an exception', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'notification_type' => $notification->getNotificationType()->value,
                    'notification_uuid' => $notification->getNotificationUUID(),
                ]);

                return new Response(null, Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
