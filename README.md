# Laravel App Store Purchases

A Laravel package for validating in-app purchase receipts, managing subscriptions, and handling server notifications from Apple, iTunes, and Amazon App Stores.

[![Latest Stable Version](https://img.shields.io/packagist/v/aporat/laravel-appstore-purchases.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-appstore-purchases)
[![Downloads](https://img.shields.io/packagist/dt/aporat/laravel-appstore-purchases.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-appstore-purchases)
[![codecov](https://codecov.io/github/aporat/laravel-appstore-purchases/graph/badge.svg?token=D44CU2TDU8)](https://codecov.io/github/aporat/laravel-appstore-purchases)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x-orange.svg?style=flat-square)](https://laravel.com/docs/12.x)
![GitHub Actions](https://img.shields.io/github/actions/workflow/status/aporat/laravel-appstore-purchases/ci.yml?style=flat-square)
[![License](https://img.shields.io/packagist/l/aporat/laravel-appstore-purchases.svg?style=flat-square)](https://github.com/aporat/laravel-appstore-purchases/blob/master/LICENSE)

---

## ✨ Features

- Dispatches Laravel events for all App Store Server Notification types
- Built-in receipt validators for Apple and Amazon
- Simple configuration via Laravel’s container and config files
- Supports Apple App Store Server API (AppTransaction, Get Transaction Info, etc.)

---

## 🛠 Installation

```bash
composer require aporat/laravel-appstore-purchases
```

---

## ⚙️ Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=config --provider="Aporat\AppStorePurchases\ServiceProviders\AppStorePurchasesServiceProvider"
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
            'environment' => Environment::SANDBOX->name,
        ],
        'itunes' => [
            'validator' => 'itunes',
            'shared_secret' => 'SHARED_SECRET',
            'environment' => Environment::SANDBOX->name,
        ],
        'amazon' => [
            'validator' => 'amazon',
            'developer_secret' => 'DEVELOPER_SECRET',
            'environment' => Environment::SANDBOX->name,
        ],
    ],
];
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

## 📦 Events

All App Store notification types are dispatched as Laravel events and extend a base `PurchaseEvent` class (except `Test`).

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
