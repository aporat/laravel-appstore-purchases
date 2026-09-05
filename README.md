# Laravel App Store Purchases

A Laravel package for validating in-app purchase receipts, managing subscriptions, and handling server notifications from the Apple App Store, iTunes, Google Play, and Amazon Appstore.

[![Latest Stable Version](https://img.shields.io/packagist/v/aporat/laravel-appstore-purchases.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-appstore-purchases)
[![Downloads](https://img.shields.io/packagist/dt/aporat/laravel-appstore-purchases.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-appstore-purchases)
[![codecov](https://codecov.io/github/aporat/laravel-appstore-purchases/graph/badge.svg?token=D44CU2TDU8)](https://codecov.io/github/aporat/laravel-appstore-purchases)
[![Laravel Version](https://img.shields.io/badge/Laravel-13.x-orange.svg?style=flat-square)](https://laravel.com/docs/13.x)
![GitHub Actions](https://img.shields.io/github/actions/workflow/status/aporat/laravel-appstore-purchases/ci.yml?style=flat-square)
[![License](https://img.shields.io/packagist/l/aporat/laravel-appstore-purchases.svg?style=flat-square)](https://github.com/aporat/laravel-appstore-purchases/blob/master/LICENSE)

---

## ✨ Features

- Dispatches Laravel events for all App Store Server Notification types
- Dispatches Laravel events for all Google Play Real-time Developer Notification types
- Built-in receipt validators for Apple, Google Play and Amazon
- Simple configuration via Laravel’s container and config files
- Supports Apple App Store Server API (AppTransaction, Get Transaction Info, etc.)
- Optional PSR-3 request/response logging via any Laravel log channel

---

## 🛠 Installation

```bash
composer require aporat/laravel-appstore-purchases
```

---

## ⚙️ Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=config --provider="Aporat\AppStorePurchases\AppStorePurchasesServiceProvider"
```

Then update `config/appstore-purchases.php` with your store credentials:

```php
use ReceiptValidator\Environment;

return [
    'validators' => [
        'apple' => [
            'validator' => 'apple-app-store',
            'key_path' => app_path('../resources/keys/authkey_ABC123XYZ.p8'),
            'key_id' => 'ABC123XYZ',
            'issuer_id' => 'DEF456UVW',
            'bundle_id' => 'com.example',
            'environment' => Environment::SANDBOX,
        ],
        'itunes' => [
            'validator' => 'itunes',
            'shared_secret' => 'SHARED_SECRET',
            'environment' => Environment::SANDBOX,
        ],
        'amazon' => [
            'validator' => 'amazon',
            'developer_secret' => 'DEVELOPER_SECRET',
            'environment' => Environment::SANDBOX,
        ],
        'google-play' => [
            'validator' => 'google-play',
            'package_name' => 'com.example',
            // Service account JSON key with access to the app in the Play Console.
            // Or pass the raw contents as 'service_account_json'.
            'service_account_key_path' => base_path('resources/keys/google-play-service-account.json'),
            // Google has no sandbox endpoint; licence-tester purchases are flagged on the response.
            'environment' => Environment::PRODUCTION,
        ],
    ],
];
```

---

## 🪵 Logging

Logging is disabled by default. To enable it, set the `APPSTORE_LOG_CHANNEL` environment variable to any Laravel log channel name:

```env
APPSTORE_LOG_CHANNEL=stack
```

This applies to all validators. When enabled, the underlying HTTP client emits structured log entries at the following levels:

| Level | When |
|-------|------|
| `debug` | Outgoing request details (method, URI, environment, query params) |
| `info` | Successful responses |
| `warning` | API error responses (non-2xx with an error body) |
| `error` | Connection failures and exceptions |

### Per-validator channel

You can also set a different log channel for an individual validator by adding a `log_channel` key to its config. This takes precedence over the global setting:

```php
'validators' => [
    'apple' => [
        'validator'   => 'apple-app-store',
        'log_channel' => 'daily',   // overrides APPSTORE_LOG_CHANNEL for this validator
        // ...
    ],
],
```

---

## 📬 Receiving Notifications

Add a route to handle server notifications from Apple:

```php
use Aporat\AppStorePurchases\Http\Controllers\AppleAppStoreServerNotificationController;

Route::prefix('server-notifications')->middleware([])->group(function () {
    Route::post('apple-appstore-callback', AppleAppStoreServerNotificationController::class);
});
```

**Security Recommendation**: Add rate limiting middleware to the notification endpoint to prevent abuse:

```php
Route::prefix('server-notifications')->middleware(['throttle:60,1'])->group(function () {
    Route::post('apple-appstore-callback', AppleAppStoreServerNotificationController::class);
});
```

This controller automatically dispatches Laravel events for **all Apple App Store Server Notification types**, including:

- `ConsumptionRequest`
- `GracePeriodExpired`
- `OfferRedeemed`
- `PurchaseRefundDeclined`
- `PurchaseRefunded`
- `PurchaseRefundReversed`
- `PurchaseRevoked`
- `SubscriptionCreated`
- `SubscriptionExpired`
- `SubscriptionFailedToRenew`
- `SubscriptionPriceIncrease`
- `SubscriptionRenewalChanged`
- `SubscriptionRenewalChangedPref`
- `SubscriptionRenewalExtended`
- `SubscriptionRenewalExtension`
- `SubscriptionRenewed`
- `ExternalPurchaseToken`
- `OneTimeCharge`
- `Test`

---

### Google Play Real-time Developer Notifications

Google Play publishes notifications to a Cloud Pub/Sub topic. Create a **push** subscription on that topic pointing at your endpoint:

```php
use Aporat\AppStorePurchases\Http\Controllers\GooglePlayServerNotificationController;

Route::prefix('server-notifications')->middleware(['throttle:60,1'])->group(function () {
    Route::post('google-play-callback', GooglePlayServerNotificationController::class);
});
```

Pub/Sub retries any non-2xx response for up to seven days, so the controller acknowledges undecodable payloads with `200` (they will never succeed) and only returns `500` when one of your listeners throws, and `401` when push verification fails.

#### Authenticating pushes

The notification body is unsigned, so enable authentication on the Pub/Sub push subscription (a service account with an OIDC token) and tell the package what to expect:

```env
GOOGLE_PLAY_RTDN_AUDIENCE=https://api.example.com/server-notifications/google-play-callback
GOOGLE_PLAY_RTDN_SERVICE_ACCOUNT_EMAIL=rtdn-push@your-project.iam.gserviceaccount.com
```

Verification uses the `google/auth` package (`composer require google/auth`). When no audience is configured, verification is skipped, which keeps local development and the Play Console's "Send test notification" button working. To replace the check entirely, bind your own `Aporat\AppStorePurchases\Contracts\PubSubPushVerifier`.

#### Events

Every notification type is dispatched as an event under `Aporat\AppStorePurchases\Events\GooglePlay`, all extending `GooglePlayEvent`:

- Subscriptions: `SubscriptionPurchased`, `SubscriptionRenewed`, `SubscriptionRecovered`, `SubscriptionRestarted`, `SubscriptionCanceled`, `SubscriptionOnHold`, `SubscriptionInGracePeriod`, `SubscriptionPaused`, `SubscriptionPauseScheduleChanged`, `SubscriptionDeferred`, `SubscriptionPriceChangeConfirmed`, `SubscriptionPriceChangeUpdated`, `SubscriptionRevoked`, `SubscriptionExpired`, `SubscriptionPendingPurchaseCanceled`, `SubscriptionUnknown`
- One-time products: `OneTimeProductPurchased`, `OneTimeProductCanceled`, `OneTimeProductUnknown`
- Refunds and chargebacks: `PurchaseVoided`
- `Test`

Unlike Apple's notifications, a Play notification only identifies the purchase token. Re-read the purchase before changing entitlement:

```php
use Aporat\AppStorePurchases\Events\GooglePlay\SubscriptionRenewed;
use Aporat\AppStorePurchases\Facades\AppStorePurchases;

Event::listen(SubscriptionRenewed::class, function (SubscriptionRenewed $event) {
    $purchase = AppStorePurchases::get('google-play')->getSubscriptionPurchaseV2($event->purchaseToken());

    foreach (Subscription::getByPurchaseToken($event->purchaseToken()) as $subscription) {
        $subscription->account->applyPlayPurchase($purchase);
    }
});
```

---

## 📦 Events

All App Store notification types are dispatched as Laravel events and extend a base `PurchaseEvent` class.

### Example: Handling a Subscription Renewal

```php
use Aporat\AppStorePurchases\Events\SubscriptionRenewed;

Event::listen(SubscriptionRenewed::class, function ($event) {
    $transaction = $event->notification->getTransaction();

    $receipts = SubscriptionReceipt::getByTransaction($transaction->getOriginalTransactionId());

    foreach ($receipts as $receipt) {
        $account = Account::find($receipt->account_id);
        $account->processSubscription($transaction);
    }
});
```

---

## ✅ Manual Receipt Validation

You can validate a transaction ID manually:

```php
$validator = AppStorePurchases::get('apple');
$response = $validator->validate($transactionId);
```

If you have a raw app receipt, extract the transaction ID first:

```php
use Aporat\AppStorePurchases\Facades\AppStorePurchases;
use ReceiptValidator\AppleAppStore\Validators\AppleAppStoreValidator;
use ReceiptValidator\AppleAppStore\ReceiptUtility;

$validator = AppStorePurchases::get('apple');

if ($validator instanceof AppleAppStoreValidator) {
    $transactionId = ReceiptUtility::extractTransactionIdFromAppReceipt($rawAppReceipt);
    $response = $validator->validate($transactionId);
}
```
