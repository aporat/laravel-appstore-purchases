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
use ReceiptValidator\AppleAppStore\ServerNotification as AppleAppStoreServerNotification;
use ReceiptValidator\AppleAppStore\ServerNotificationType as AppleAppStoreServerNotificationType;

final class AppleAppStoreServerNotificationController
{
    public function __invoke(Request $request): Response
    {
        $notification = new AppleAppStoreServerNotification($request->all());

        match ($notification->getNotificationType()) {
            AppleAppStoreServerNotificationType::CONSUMPTION_REQUEST => event(new ConsumptionRequest($notification)),
            AppleAppStoreServerNotificationType::GRACE_PERIOD_EXPIRED => event(new GracePeriodExpired($notification)),
            AppleAppStoreServerNotificationType::OFFER_REDEEMED => event(new OfferRedeemed($notification)),
            AppleAppStoreServerNotificationType::REFUND_DECLINED => event(new PurchaseRefundDeclined($notification)),
            AppleAppStoreServerNotificationType::REFUND_REVERSED => event(new PurchaseRefundReversed($notification)),
            AppleAppStoreServerNotificationType::SUBSCRIBED => event(new SubscriptionCreated($notification)),
            AppleAppStoreServerNotificationType::EXPIRED => event(new SubscriptionExpired($notification)),
            AppleAppStoreServerNotificationType::DID_CHANGE_RENEWAL_STATUS => event(new SubscriptionRenewalChanged($notification)),
            AppleAppStoreServerNotificationType::DID_RENEW => event(new SubscriptionRenewed($notification)),
            AppleAppStoreServerNotificationType::TEST => event(new Test($notification)),
            AppleAppStoreServerNotificationType::DID_FAIL_TO_RENEW => event(new SubscriptionFailedToRenew($notification)),
            AppleAppStoreServerNotificationType::PRICE_INCREASE => event(new SubscriptionPriceIncrease($notification)),
            AppleAppStoreServerNotificationType::REFUND => event(new PurchaseRefunded($notification)),
            AppleAppStoreServerNotificationType::RENEWAL_EXTENDED => event(new SubscriptionRenewalExtended($notification)),
            AppleAppStoreServerNotificationType::REVOKE => event(new PurchaseRevoked($notification)),
            AppleAppStoreServerNotificationType::EXTERNAL_PURCHASE_TOKEN => event(new ExternalPurchaseToken($notification)),
            AppleAppStoreServerNotificationType::ONE_TIME_CHARGE => event(new OneTimeCharge($notification)),
            AppleAppStoreServerNotificationType::DID_CHANGE_RENEWAL_PREF => event(new SubscriptionRenewalChangedPref($notification)),
            AppleAppStoreServerNotificationType::RENEWAL_EXTENSION => event(new SubscriptionRenewalExtension($notification)),
            default => null,
        };

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
