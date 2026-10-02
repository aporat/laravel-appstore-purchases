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

- Built-in receipt validators for the Apple App Store (Server API and StoreKit 2), legacy iTunes, Google Play and Amazon, configured once and resolved from the container
- Dispatches Laravel events for all App Store Server Notification types, after verifying each notification belongs to one of your configured apps
- Dispatches Laravel events for all Google Play Real-time Developer Notification types, with Pub/Sub push authentication
- Replay protection on both notification endpoints
- Optional PSR-3 request/response logging via any Laravel log channel

Validation itself is done by [aporat/store-receipt-validator](https://github.com/aporat/store-receipt-validator); this package wires it into Laravel.

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
            // Optional: the app's numeric Apple ID from App Store Connect. In production,
            // signed app transactions and server notifications must then carry it.
            'app_apple_id' => 1234567890,
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

The keys under `validators` are names you choose. Each entry builds one validator, so an API that serves several apps simply declares one entry per app.

### Validator names

The `validator` key accepts `apple-app-store`, `itunes`, `amazon` and
`google-play`. Camel, studly and snake spellings of those (`appleAppStore`,
`AppleAppStore`, `apple_app_store`) and the short aliases `apple`, `google` and
`play` resolve to the same drivers. `AppStorePurchases::supportedValidators()`
returns the canonical list.

### Reading the config yourself

The service provider is deferred: its config is merged into the application the first time one of its services (the manager, a controller, the facade) is resolved. Package code is always past that point, but if your own code reads `config('appstore-purchases.…')` before anything has resolved the package, publish the config file so the keys exist from boot.

---

## ✅ Validating Purchases

`AppStorePurchases::get()` returns the validator built from the named config entry. It is the store's validator class from [store-receipt-validator](https://github.com/aporat/store-receipt-validator), so everything that library documents is available on it; the examples below cover the common path for each store.

```php
use Aporat\AppStorePurchases\Facades\AppStorePurchases;

$validator = AppStorePurchases::get('apple');
```

Validators are cached per name, so every caller shares one instance. The facade is typed as the library's `AbstractValidator`; narrow it with `instanceof` (or a `@var` annotation) when you want IDE completion or static analysis to see the store-specific methods.

### 📲 Apple App Store

StoreKit 2 apps send a signed transaction (`jwsRepresentation`) rather than an app receipt. Verify it offline, which checks Apple's signature and certificate chain and that the payload's bundle ID and environment match the validator:

```php
use Aporat\AppStorePurchases\Facades\AppStorePurchases;
use ReceiptValidator\Exceptions\ValidationException;

$validator = AppStorePurchases::get('apple');

try {
    $transaction = $validator->verifySignedTransaction($jwsRepresentation);
} catch (ValidationException $e) {
    // Bad signature, untrusted chain, or bundle/environment mismatch
    abort(422, $e->getMessage());
}

$transaction->getProductId();
$transaction->getTransactionId();
$transaction->getOriginalTransactionId();
$transaction->getExpiresDate();   // subscriptions only
```

A signed transaction reflects the purchase at the time it was signed. To see its current state (a later refund, for example), look it up with the App Store Server API:

```php
$current = $validator->getTransactionInfo($transaction->getTransactionId());

if ($current->getRevocationDate() !== null) {
    // refunded or revoked by Apple
}
```

If the app still sends a full app receipt (StoreKit 1), Apple's Server API does not accept it directly. Extract the latest transaction ID and query the history for it:

```php
use ReceiptValidator\AppleAppStore\APIError;
use ReceiptValidator\AppleAppStore\ReceiptUtility;
use ReceiptValidator\Exceptions\ValidationException;

$transactionId = ReceiptUtility::extractTransactionIdFromAppReceipt($receiptBase64Data);

try {
    $response = $validator->getTransactionHistory($transactionId);
} catch (ValidationException $e) {
    if ($e->getCode() === APIError::INVALID_TRANSACTION_ID->value) {
        abort(422, 'Invalid transaction ID');
    }

    throw $e;
}

foreach ($response->getTransactions() as $transaction) {
    $transaction->getProductId();
    $transaction->getPurchaseDate();
}

// $response->hasMore() with $response->getRevision() pages through the rest
```

The validator covers the whole App Store Server API:

| Area | Methods |
|---|---|
| Transactions | `getTransactionHistory()`, `getTransactionInfo()`, `getAppTransactionInfo()`, `finishTransaction()`, `setAppAccountToken()`, `sendConsumptionInformation()` |
| Signed payloads | `verifySignedTransaction()`, `verifySignedRenewalInfo()`, `verifySignedAppTransaction()`, `verifyNotification()` |
| Order / refunds | `lookUpOrderId()`, `getRefundHistory()` |
| Subscriptions | `getAllSubscriptionStatuses()`, `extendSubscriptionRenewalDate()`, `extendSubscriptionRenewalDatesForAllActiveSubscribers()`, `getStatusOfSubscriptionRenewalDateExtensions()` |
| Notifications | `requestTestNotification()`, `getTestNotificationStatus()`, `getNotificationHistory()` |

> ℹ️ `validate()` on the Apple validator is deprecated upstream. Use `getTransactionInfo()` for a single transaction or `getTransactionHistory()` for paginated history.

### 🍏 Apple iTunes (legacy, deprecated by Apple)

The `verifyReceipt` endpoint still works for apps that have not moved to the Server API:

```php
use Aporat\AppStorePurchases\Facades\AppStorePurchases;
use ReceiptValidator\Exceptions\ValidationException;

try {
    $response = AppStorePurchases::get('itunes')->validate($receiptBase64Data);
} catch (ValidationException $e) {
    abort(422, $e->getMessage());
}

$response->getBundleId();

foreach ($response->getLatestReceiptInfo() as $transaction) {
    $transaction->getProductId();
    $transaction->getOriginalTransactionId();
    $transaction->getExpiresDate();
}
```

### 🤖 Google Play

Authentication uses the service account from the config entry. The purchase token comes from `Purchase.getPurchaseToken()` in the app:

```php
use Aporat\AppStorePurchases\Facades\AppStorePurchases;
use ReceiptValidator\GooglePlay\APIError;
use ReceiptValidator\GooglePlay\APIException;

$validator = AppStorePurchases::get('google-play');

try {
    $purchase = $validator->getSubscriptionPurchaseV2($purchaseToken);
} catch (APIException $e) {
    if ($e->isRetryable()) {            // 429, 5xx, quota or backend errors
        // back off and try again later
    }

    if ($e->getError() === APIError::PURCHASE_TOKEN_NO_LONGER_VALID) {
        // the token was superseded; drop it
    }

    throw $e;
}

$purchase->isEntitled();
$purchase->getSubscriptionState();
$purchase->getExpiryTime();
$purchase->isTestPurchase();        // a licence-tester purchase

foreach ($purchase->getLineItems() as $item) {
    $item->getProductId();
    $item->getBasePlanId();
    $item->isAutoRenewEnabled();
}
```

One-time products use the v2 product lookup, and must be acknowledged within three days or Google refunds them:

```php
$product = $validator->getProductPurchaseV2($purchaseToken);

if ($product->isPurchased() && ! $product->isAcknowledged()) {
    foreach ($product->getLineItems() as $item) {
        $validator->acknowledgeProduct($item->getProductId(), $purchaseToken);
    }
}
```

| Area | Methods |
|---|---|
| Subscriptions | `getSubscriptionPurchaseV2()`, `acknowledgeSubscription()`, `cancelSubscription()`, `deferSubscription()`, `revokeSubscription()` |
| One-time products | `getProductPurchaseV2()`, `getProductPurchase()`, `acknowledgeProduct()`, `consumeProduct()` |
| Orders | `getOrder()`, `getOrders()`, `refundOrder()`, `reviewRefund()` |
| Refunds | `getVoidedPurchases()` |

> ℹ️ Google has no sandbox endpoint, so the `environment` of a Google Play entry is informational. Licence-tester purchases come back from the production API flagged as `isTestPurchase()`.

### 🛒 Amazon Appstore

Pass the receipt ID and user ID the app received in its `PurchaseResponse`:

```php
use Aporat\AppStorePurchases\Facades\AppStorePurchases;
use ReceiptValidator\Amazon\APIError;
use ReceiptValidator\Exceptions\ValidationException;

try {
    $response = AppStorePurchases::get('amazon')->validate($receiptId, $userId);
} catch (ValidationException $e) {
    $error = APIError::fromException($e); // null for connection failures

    if ($error?->isCanceledReceipt()) {
        // HTTP 410: the receipt was valid once. Revoke what it granted.
    } elseif ($error?->isRetryable()) {
        // HTTP 429 or 500: back off and try again later.
    }

    abort(422, $e->getMessage());
}

$response->getProductId();
$response->getProductType();      // CONSUMABLE, ENTITLED or SUBSCRIPTION
$response->isEntitled();
$response->getExpiresAt();        // subscriptions only

$transaction = $response->getTransaction();

if ($transaction?->isSubscription()) {
    $transaction->isAutoRenewing();
    $transaction->isInFreeTrial();
    $transaction->isInGracePeriod();
}
```

### Validating against a different environment

`get()` caches one validator per name and hands the same instance to every
caller, so calling `setEnvironment()` on it leaks the change into unrelated
lookups for the rest of the process (a queue worker or Octane server handling
one sandbox receipt would point every later production lookup at the sandbox
endpoint). Pass the environment to `get()` instead; it returns a separate,
separately cached instance and leaves the configured one alone:

```php
use ReceiptValidator\Environment;

$sandbox = AppStorePurchases::get('apple', Environment::SANDBOX);
$sandbox = AppStorePurchases::get('apple', 'sandbox'); // strings work too
```

---

## 📬 Receiving Notifications

### Apple App Store Server Notifications

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

#### Verification

Every notification is verified before any event fires. The controller reads the bundle ID from the signed payload, finds the Apple App Store validator entry with that `bundle_id`, and calls the library's `verifyNotification()` on it. That checks Apple's signature and certificate chain, the bundle ID, the environment and, for production notifications, the `app_apple_id` when one is configured. Serving several apps from one endpoint just means one validator entry per bundle ID.

A notification is rejected with `401` when its signature does not verify, when no entry declares its bundle ID, or when verification fails, and with `400` when the payload cannot be decoded at all. Apple retries non-2xx responses a few times and then stops, so nothing foreign or forged reaches a listener. Entries whose `bundle_id` is empty (the unpublished default) never match.

Sandbox and production notifications are both accepted for a configured bundle ID regardless of the environment the entry itself names, since Apple sends sandbox notifications during review and to the same URL if you only register one. The validator is resolved for the notification's environment, and `$event->notification->getEnvironment()` tells listeners which it was.

#### Events

Every notification type is dispatched as an event under `Aporat\AppStorePurchases\Events`, all extending `PurchaseEvent`:

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

The event carries the verified `ServerNotification`, whose transaction and renewal info are already decoded:

```php
use Aporat\AppStorePurchases\Events\SubscriptionRenewed;

Event::listen(SubscriptionRenewed::class, function (SubscriptionRenewed $event) {
    $transaction = $event->notification->getTransaction();

    $receipts = SubscriptionReceipt::getByTransaction($transaction->getOriginalTransactionId());

    foreach ($receipts as $receipt) {
        $account = Account::find($receipt->account_id);
        $account->processSubscription($transaction);
    }
});
```

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

Serving several Play apps from one API, each with its own Cloud project and push subscription? Give each Google Play validator entry its own `rtdn` block. The verifier reads the package name from the notification body and checks the token against that app's audience and signing account, so a push claiming to be for one app cannot be signed by another app's subscription. The global `google_play.rtdn` block is the fallback for packages without their own entry, and every setting accepts a string or a list:

```php
'validators' => [
    'app-one' => [
        'validator' => 'google-play',
        'package_name' => 'com.example.one',
        'service_account_key_path' => base_path('resources/keys/one.json'),
        'rtdn' => [
            'audience' => 'https://api.one.example/server-notifications/google-play-callback',
            'service_account_email' => 'rtdn-push@project-one.iam.gserviceaccount.com',
        ],
    ],
    'app-two' => [
        'validator' => 'google-play',
        'package_name' => 'com.example.two',
        'service_account_key_path' => base_path('resources/keys/two.json'),
        'rtdn' => [
            'audience' => 'https://api.two.example/server-notifications/google-play-callback',
            'service_account_email' => 'rtdn-push@project-two.iam.gserviceaccount.com',
        ],
    ],
],
```

Verification uses the `google/auth` package, which is a hard dependency of this package. When no audience is configured, verification is skipped and a warning is logged for every push accepted that way, which keeps local development and the Play Console's "Send test notification" button working. **Always set an audience in production**, or the endpoint accepts anything. To replace the check entirely, bind your own `Aporat\AppStorePurchases\Contracts\PubSubPushVerifier`.

#### Events

Every notification type is dispatched as an event under `Aporat\AppStorePurchases\Events\GooglePlay`, all extending `GooglePlayEvent`:

- Subscriptions: `SubscriptionPurchased`, `SubscriptionRenewed`, `SubscriptionRecovered`, `SubscriptionRestarted`, `SubscriptionCanceled`, `SubscriptionOnHold`, `SubscriptionInGracePeriod`, `SubscriptionPaused`, `SubscriptionPauseScheduleChanged`, `SubscriptionDeferred`, `SubscriptionPriceChangeConfirmed`, `SubscriptionPriceChangeUpdated`, `SubscriptionRevoked`, `SubscriptionExpired`, `SubscriptionItemsChanged`, `SubscriptionCancellationScheduled`, `SubscriptionPendingPurchaseCanceled`, `SubscriptionPriceStepUpConsentUpdated`, `SubscriptionUnknown`
- One-time products: `OneTimeProductPurchased`, `OneTimeProductCanceled`, `OneTimeProductUnknown`
- Refunds and chargebacks: `PurchaseVoided`, `PendingRefundReview`
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

### Replay protection

Both endpoints remember the IDs they have handled (Apple's `notificationUUID`, Pub/Sub's `messageId`) in a cache store and acknowledge a repeated delivery without dispatching its event again. That deduplicates the stores' own retries and stops a captured notification from being replayed at the endpoint. When one of your listeners throws and the endpoint answers `500`, the ID is released so the store's retry is processed normally.

It is on by default and uses the application's default cache store with a seven-day window, the longest either store keeps retrying. Behind a load balancer use a store every server shares:

```env
APPSTORE_REPLAY_PROTECTION=true
APPSTORE_REPLAY_CACHE_STORE=redis
APPSTORE_REPLAY_TTL=604800
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

### Notification endpoints

The two notification controllers and the Pub/Sub push verifier log separately from the validators: decode failures, listener exceptions, rejected pushes (with the token's unverified claims) and one `info` line per verified push. They write to the application's default channel unless you route them elsewhere:

```env
APPSTORE_NOTIFICATIONS_LOG_CHANNEL=stack
```

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

## 📝 Changelog

Notable changes in each release are listed in the [CHANGELOG](CHANGELOG.md).
