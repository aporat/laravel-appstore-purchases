# Laravel App Store Purchases

Laravel package for handling App Store (Apple, iTunes, Amazon)  purchase receipts, subscriptions and notifications.

[![Latest Stable Version](https://img.shields.io/packagist/v/aporat/laravel-appstore-purchases.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-appstore-purchases)
[![Downloads](https://img.shields.io/packagist/dt/aporat/laravel-appstore-purchases.svg?style=flat-square&logo=composer)](https://packagist.org/packages/aporat/laravel-appstore-purchases)
[![codecov](https://codecov.io/github/aporat/laravel-appstore-purchases/graph/badge.svg?token=D44CU2TDU8)](https://codecov.io/github/aporat/laravel-appstore-purchases)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x-orange.svg?style=flat-square)](https://laravel.com/docs/12.x)
![GitHub Actions Workflow Status](https://img.shields.io/github/actions/workflow/status/aporat/laravel-appstore-purchases/ci.yml?style=flat-square)
[![License](https://img.shields.io/packagist/l/aporat/laravel-appstore-purchases.svg?style=flat-square)](https://github.com/aporat/laravel-appstore-purchases/blob/master/LICENSE)


## ✨ Features

- Dispatches Laravel events for each notification type
- Built-in manager for validating Apple App Store transactions
- Easy integration with Laravel's service container

## 🛠 Installation

```bash
composer require aporat/laravel-appstore-purchases
```

## ⚙️ Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=config --provider="Aporat\AppStorePurchases\ServiceProviders\AppStorePurchasesServiceProvider"
```

Then edit `config/appstore-purchases.php`:

```php
return [
    'validators' => [
        'apple' => [
            'validator' => 'appleAppStore',
            'key_path' => base_path('resources/keys/AuthKey_ABC123XYZ.p8'),
            'key_id' => env('APPSTORE_KEY_ID'),
            'issuer_id' => env('APPSTORE_ISSUER_ID'),
            'bundle_id' => env('APPSTORE_BUNDLE_ID'),
            'environment' => \ReceiptValidator\Environment::PRODUCTION,
        ],
    ],
];
```

## 🚀 Usage

### Receiving Notifications

Create a route in `routes/api.php`:

```php
use Aporat\AppStorePurchases\Http\Controllers\AppleAppStoreServerNotificationController;

Route::post('/apple/notifications', AppleAppStoreServerNotificationController::class);
```

The controller will dispatch Laravel events like:

- `SubscriptionCreated`
- `SubscriptionRenewed`
- `SubscriptionExpired`
- `SubscriptionRenewalChanged`
- `ConsumptionRequest`

You can listen to them in `EventServiceProvider`.

### Validating a Transaction Manually

```php
$manager = app(\Aporat\AppStorePurchases\AppStorePurchasesManager::class);

$response = $manager->validate('apple', $transactionId);

// You can now inspect the decoded transaction info
```

## 📦 Events

All App Store notification types are mapped to Laravel events. You can handle only what you need. Example:

```php
use Aporat\AppStorePurchases\Events\SubscriptionCreated;

Event::listen(SubscriptionCreated::class, function ($event) {
    $transaction = $event->transaction;
    // store or log transaction
});
```