<?php

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
use ReceiptValidator\AppleAppStore\ServerNotification as AppleAppStoreServerNotification;
use ReceiptValidator\AppleAppStore\ServerNotificationType as AppleAppStoreServerNotificationType;

class AppleAppStoreServerNotificationController
{
    public function __invoke(Request $request): Response
    {
        $notification = new AppleAppStoreServerNotification($request->all());
        $transaction = $notification->getTransaction();

        switch ($notification->getNotificationType()) {
            case AppleAppStoreServerNotificationType::CONSUMPTION_REQUEST:
                event(new ConsumptionRequest($notification));
                break;
            case AppleAppStoreServerNotificationType::GRACE_PERIOD_EXPIRED:
                event(new GracePeriodExpired($notification));
                break;
            case AppleAppStoreServerNotificationType::OFFER_REDEEMED:
                event(new OfferRedeemed($notification));
                break;
            case AppleAppStoreServerNotificationType::REFUND_DECLINED:
                event(new PurchaseRefundDeclined($notification));
                break;
            case AppleAppStoreServerNotificationType::REFUND_REVERSED:
                event(new PurchaseRefundReversed($notification));
                break;
            case AppleAppStoreServerNotificationType::SUBSCRIBED:
                event(new SubscriptionCreated($notification));
                break;
            case AppleAppStoreServerNotificationType::EXPIRED:
                event(new SubscriptionExpired($notification));
                break;
            case AppleAppStoreServerNotificationType::DID_CHANGE_RENEWAL_STATUS:
                event(new SubscriptionRenewalChanged($notification));
                break;
            case AppleAppStoreServerNotificationType::DID_RENEW:
                event(new SubscriptionRenewed($notification));
                break;
            case AppleAppStoreServerNotificationType::TEST:
                event(new Test($notification));
                break;
            case AppleAppStoreServerNotificationType::DID_FAIL_TO_RENEW:
                event(new SubscriptionFailedToRenew($notification));
                break;
            case AppleAppStoreServerNotificationType::PRICE_INCREASE:
                event(new SubscriptionPriceIncrease($notification));
                break;
            case AppleAppStoreServerNotificationType::REFUND:
                event(new PurchaseRefunded($notification));
                break;
            case AppleAppStoreServerNotificationType::RENEWAL_EXTENDED:
                event(new SubscriptionRenewalExtended($notification));
                break;
            case AppleAppStoreServerNotificationType::REVOKE:
                event(new PurchaseRevoked($notification));
                break;
            case AppleAppStoreServerNotificationType::EXTERNAL_PURCHASE_TOKEN:
                event(new ExternalPurchaseToken($notification));
                break;
            case AppleAppStoreServerNotificationType::ONE_TIME_CHARGE:
                event(new OneTimeCharge($notification));
                break;
            case AppleAppStoreServerNotificationType::DID_CHANGE_RENEWAL_PREF:
                event(new SubscriptionRenewalChangedPref($notification));
                break;
            case AppleAppStoreServerNotificationType::RENEWAL_EXTENSION:
                event(new SubscriptionRenewalExtension($notification));
        }

        return new Response(null, 204);
    }
}
