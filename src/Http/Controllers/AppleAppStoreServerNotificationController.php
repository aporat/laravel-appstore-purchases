<?php

namespace Aporat\AppStorePurchases\Http\Controllers;

use Aporat\AppStorePurchases\Events\ConsumptionRequest;
use Aporat\AppStorePurchases\Events\SubscriptionCreated;
use Aporat\AppStorePurchases\Events\SubscriptionExpired;
use Aporat\AppStorePurchases\Events\SubscriptionRenewalChanged;
use Aporat\AppStorePurchases\Events\SubscriptionRenewed;
use Aporat\AppStorePurchases\Events\Test;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use ReceiptValidator\AppleAppStore\ServerNotification as AppleAppStoreServerNotification;
use ReceiptValidator\AppleAppStore\ServerNotificationType as AppleAppStoreServerNotificationType;

class AppleAppStoreServerNotificationController
{
    /**
     * Handles the server notification request.
     *
     * @throws Exception
     */
    public function __invoke(Request $request): Response
    {
        $notification = new AppleAppStoreServerNotification($request->all());
        $transaction = $notification->getTransaction();

        switch ($notification->getNotificationType()) {
            case AppleAppStoreServerNotificationType::CONSUMPTION_REQUEST:
                event(new ConsumptionRequest($transaction));
                break;
            case AppleAppStoreServerNotificationType::SUBSCRIBED:
                event(new SubscriptionCreated($transaction));
                break;
            case AppleAppStoreServerNotificationType::DID_CHANGE_RENEWAL_STATUS:
                event(new SubscriptionRenewalChanged($transaction));
                break;
            case AppleAppStoreServerNotificationType::DID_RENEW:
                event(new SubscriptionRenewed($transaction));
                break;
            case AppleAppStoreServerNotificationType::EXPIRED:
                event(new SubscriptionExpired($transaction));
                break;
            case AppleAppStoreServerNotificationType::TEST:
                event(new Test);
                break;
            default:
                break;
        }

        return response(null, 204);
    }
}
