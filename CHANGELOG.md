# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

Entries for released versions were written from the code diff between each tag and
the one before it. The original GitHub releases carried no notes, so where a change
is attributed it is to the pull request or commit that made it.

## [Unreleased]

### Added
- Replay protection on both notification endpoints. `Support\NotificationReplayGuard` records each
  handled Apple `notificationUUID` and Pub/Sub `messageId` in a cache store and a repeated delivery
  is acknowledged (204 for Apple, `{"status": "ignored: duplicate"}` for Play) without dispatching
  its event again. The ID is released when a listener throws so the store's retry is processed.
  Configured under `replay_protection` (`APPSTORE_REPLAY_PROTECTION`, on by default;
  `APPSTORE_REPLAY_CACHE_STORE`, default store when unset; `APPSTORE_REPLAY_TTL`, seven days).
- `AppStorePurchasesManager::googlePlayRtdnExpectationsByPackageName()` collects the per-app `rtdn`
  blocks of the configured Google Play validator entries, keyed by package name.
- The push verifier logs a warning for every push it accepts without verification because no
  audience is configured, so a production endpoint that forgot its audience is visible in the logs.
- A README note on reading the package config before the deferred provider has loaded.
- Larastan with PHPStan at level 9 (`phpstan.neon`); `composer analyze` runs it.
- Apple App Store server notifications are verified against the configured app.
  `AppleAppStoreServerNotificationController` looks up the Apple validator whose `bundle_id`
  matches the notification, resolves it for the notification's environment and calls
  `verifyNotification()` on it, so the signature, bundle ID, environment and (when configured) app
  Apple ID must all match. A notification for a bundle ID with no configured validator, or one that
  fails verification, is answered with 401 and logged. (commit 527c39e)
- Optional `app_apple_id` key on Apple App Store validator entries
  (`APPLE_APPSTORE_APP_APPLE_ID`), passed to the validator as `appAppleId`. Numeric strings from the
  environment are accepted; any other non-empty value raises `InvalidArgumentException`.
  (commit 527c39e)
- `AppStorePurchasesManager::appleAppStoreValidatorsByBundleId()` returns the configured Apple
  validator names keyed by bundle ID (entries without a bundle ID are skipped, first one wins on
  duplicates). (commit 527c39e)
- `AppStorePurchasesManager::class` is now a container alias of the `appstore-purchases` binding,
  so the manager can be constructor-injected. (commit 527c39e)
- Google Play RTDN events `PendingRefundReview` (for the `pendingRefundReviewNotification`
  payload), `SubscriptionItemsChanged` (17), `SubscriptionCancellationScheduled` (18) and
  `SubscriptionPriceStepUpConsentUpdated` (22). (commit e99463c)
- `GoogleOidcPushVerifier` accepts a string or a list for both the audience and the service
  account email, trying the token against each audience and accepting any listed signing account,
  so one API can receive pushes for several Play apps whose subscriptions live in different Cloud
  projects. `audiences()` and `serviceAccountEmails()` expose the normalised lists.
  ([#18](https://github.com/aporat/laravel-appstore-purchases/pull/18))
- Per-app RTDN expectations: a `google-play` validator entry can carry its own `rtdn` block
  (`audience`, `service_account_email`, string or list). The verifier reads `packageName` from the
  Pub/Sub body and checks the token against that app's pair; packages without an entry fall back to
  the global `google_play.rtdn` block, and an app entry with a null audience turns verification off
  for that app only. `apps()` exposes the per-package map. The service provider builds the map from
  validator entries whose `validator` is `google-play`, `google` or `play`.
  ([#19](https://github.com/aporat/laravel-appstore-purchases/pull/19))
- `Logging\NotificationLogger`, a PSR-3 logger that writes to the channel named by the new
  `logging.notifications_channel` config key (`APPSTORE_NOTIFICATIONS_LOG_CHANNEL`), or the
  application's default channel when unset. Both notification controllers and the push verifier
  log through it instead of the `Log` facade. (commit acf43dd)
- The push verifier logs why a token was rejected: the audiences tried and the token's unverified
  `iss`, `aud`, `email`, `azp`, `sub`, `iat`, `exp` claims plus an `expired` flag (the token itself
  is never logged), and writes an `info` line for every verified push. (commit 494f004)

### Changed
- **Breaking:** `aporat/store-receipt-validator` is required as `^11.0` instead of `dev-main`, and
  the package's `minimum-stability` is `stable`. Consumers no longer need to allow dev stability.
- **Breaking:** `laravel/framework` `^12.0 || ^13.0` is required in place of `illuminate/support`.
  The package uses the HTTP, log and foundation components, which `illuminate/support` alone does
  not provide; every Laravel application already satisfies this.
- **Breaking:** `AppleAppStoreServerNotificationController` now requires an Apple App Store
  validator whose `bundle_id` matches the incoming notification. Applications that relied on the
  endpoint accepting any Apple-signed notification must configure the bundle ID, and now receive a
  401 for notifications from other apps. The controller also takes `AppStorePurchasesManager`,
  `NotificationLogger` and `NotificationReplayGuard` in its constructor. (commit 527c39e)
- An Apple notification whose JWS signature does not verify is answered with 401 and a warning,
  rather than 400; 400 is now reserved for payloads that cannot be decoded at all.
- The service provider builds the per-app RTDN map through the manager, so any accepted spelling of
  the Google Play driver name (`googlePlay`, `google_play`, `play`, ...) contributes its `rtdn` block.
  Previously only the literal `google-play`, `google` and `play` did, and other spellings silently
  fell back to the global expectations.
- Dev tooling: `phpseclib/phpseclib` updated in the lock file from 3.0.52 to 4.0.1, clearing two
  medium advisories (CVE-2026-55599, CVE-2026-84308); the unused `allow-plugins` entry was
  dropped; `.gitignore` reduced to what a package produces.
- **Breaking:** `GoogleOidcPushVerifier::__construct()` changed shape: `$audience` and
  `$serviceAccountEmail` are `string|array|null`, a new `$apps` parameter was inserted before
  `$verifier`, and a trailing `$logger` parameter was added. Code constructing the verifier with a
  positional `$verifier` must be updated.
  ([#18](https://github.com/aporat/laravel-appstore-purchases/pull/18),
  [#19](https://github.com/aporat/laravel-appstore-purchases/pull/19), commit acf43dd)
- `GooglePlayServerNotificationController` takes `NotificationLogger` and `NotificationReplayGuard`
  as further constructor arguments. (commit acf43dd)
- Dev dependencies refreshed in the lock file: `phpstan/phpstan` 2.2.16, `laravel/pint` 1.32.1,
  `orchestra/testbench` 11.3.0 and `google/auth` 1.55.1.
  ([#20](https://github.com/aporat/laravel-appstore-purchases/pull/20),
  [#21](https://github.com/aporat/laravel-appstore-purchases/pull/21),
  [#22](https://github.com/aporat/laravel-appstore-purchases/pull/22),
  [#23](https://github.com/aporat/laravel-appstore-purchases/pull/23))

## [3.0.0] - 2026-09-19

### Added
- Google Play support. A `google-play` validator driver built from `package_name` and either
  `service_account_key_path` or `service_account_json`, wrapping
  `ReceiptValidator\GooglePlay\Validator`; `GooglePlayServerNotificationController`, which receives
  Real-time Developer Notifications from a Cloud Pub/Sub push subscription and dispatches them as
  events (undecodable payloads are acknowledged with 200 and logged, listener failures return 500 so
  Pub/Sub retries, failed push verification returns 401); an `Events\GooglePlay` namespace with a
  `GooglePlayEvent` base (`readonly $notification`, `purchaseToken()`) and `Test`,
  `PurchaseVoided`, `OneTimeProductPurchased`, `OneTimeProductCanceled`, `OneTimeProductUnknown`,
  `SubscriptionRecovered`, `SubscriptionRenewed`, `SubscriptionCanceled`, `SubscriptionPurchased`,
  `SubscriptionOnHold`, `SubscriptionInGracePeriod`, `SubscriptionRestarted`,
  `SubscriptionPriceChangeConfirmed`, `SubscriptionDeferred`, `SubscriptionPaused`,
  `SubscriptionPauseScheduleChanged`, `SubscriptionRevoked`, `SubscriptionExpired`,
  `SubscriptionPriceChangeUpdated`, `SubscriptionPendingPurchaseCanceled` and
  `SubscriptionUnknown`. (commit c376053)
- `Contracts\PubSubPushVerifier` and its default binding `GooglePlay\GoogleOidcPushVerifier`, which
  verifies the Google-signed OIDC bearer token Pub/Sub attaches to pushes against the configured
  `google_play.rtdn.audience` and optional `google_play.rtdn.service_account_email`. With no
  audience configured every push is accepted. Bind your own implementation to change this.
  (commit c376053)
- `google/auth` is a hard requirement. It was only a `suggest`, and a missing package made the
  verifier answer 401 to every notification while Pub/Sub retried for seven days.
  ([#17](https://github.com/aporat/laravel-appstore-purchases/pull/17))
- PSR-3 logging on validators: a `logging.channel` config key (`APPSTORE_LOG_CHANNEL`) and a
  per-validator `log_channel` key attach the named Laravel log channel to each validator via
  `setLogger()`. Unset leaves the library's `NullLogger` in place. (commit b813551)
- `AppStorePurchasesManager::get()` takes an optional second argument, `Environment|string|null
  $environment`. It returns a separate, separately cached instance for that environment instead of
  requiring `setEnvironment()` on the shared one, which leaked the override into unrelated calls for
  the life of a queue worker or Octane process.
  ([#17](https://github.com/aporat/laravel-appstore-purchases/pull/17))
- Driver names are normalised through an explicit driver map: kebab, camel, studly and snake
  spellings (`apple-app-store`, `appleAppStore`, `AppleAppStore`, `apple_app_store`) and the aliases
  `apple`, `app-store`, `apple-appstore`, `i-tunes`, `google` and `play` all resolve.
  `supportedValidators()` is derived from the map and now lists `google-play`.
  ([#17](https://github.com/aporat/laravel-appstore-purchases/pull/17))
- Configuration is validated with specific messages: a validator entry that is missing, not an
  array, or missing its `validator` or `environment` key; an unsupported driver; an `environment`
  value that is neither a string nor an `Environment`; and missing per-driver keys (`key_path`,
  `key_id`, `issuer_id`, `bundle_id`, `shared_secret`, `developer_secret`, `package_name`,
  `service_account_key_path` / `service_account_json`) raise `InvalidArgumentException`. A key file
  that is missing, unreadable or empty raises `RuntimeException`. (commit ac4ceca,
  [#17](https://github.com/aporat/laravel-appstore-purchases/pull/17))
- `AppleAppStoreServerNotificationController` error handling: a payload that cannot be decoded is
  logged (with the payload size, not its contents) and answered with 400; a notification type with
  no mapped event logs a warning; a listener that throws is logged and answered with 500 so Apple
  retries. Previously any exception propagated out of the controller.
  ([#4](https://github.com/aporat/laravel-appstore-purchases/pull/4), commits ac4ceca and c6d4b66)
- The config file reads from environment variables: `APPLE_APPSTORE_KEY_PATH`,
  `APPLE_APPSTORE_KEY_ID`, `APPLE_APPSTORE_ISSUER_ID`, `APPLE_APPSTORE_BUNDLE_ID`,
  `APPLE_APPSTORE_ENVIRONMENT`, `ITUNES_SHARED_SECRET`, `ITUNES_ENVIRONMENT`,
  `AMAZON_DEVELOPER_SECRET`, `AMAZON_ENVIRONMENT`, `GOOGLE_PLAY_PACKAGE_NAME`,
  `GOOGLE_PLAY_SERVICE_ACCOUNT_KEY_PATH`, `GOOGLE_PLAY_ENVIRONMENT`, `GOOGLE_PLAY_RTDN_AUDIENCE`
  and `GOOGLE_PLAY_RTDN_SERVICE_ACCOUNT_EMAIL`.
  ([#5](https://github.com/aporat/laravel-appstore-purchases/pull/5), commit c376053)
- `declare(strict_types=1)` across the source, and a facade test plus broad manager and controller
  test coverage. ([#5](https://github.com/aporat/laravel-appstore-purchases/pull/5), commits c6d4b66
  and 0ba53f8)
- CI runs `composer analyze` (PHPStan) alongside Pint and PHPUnit.
  ([#5](https://github.com/aporat/laravel-appstore-purchases/pull/5))

### Changed
- **Breaking:** Laravel 10 and 11 support dropped; `illuminate/support` is now `^12.0 || ^13.0`.
  ([#8](https://github.com/aporat/laravel-appstore-purchases/pull/8))
- **Breaking:** Minimum PHP version raised back from `^8.3` to `^8.4`, with CI covering 8.4 and
  8.5. (commit de4b5e3)
- **Breaking:** `PurchaseEvent::$notification` is `readonly`.
  ([#5](https://github.com/aporat/laravel-appstore-purchases/pull/5))
- **Breaking:** `AppStorePurchasesManager::getConfig()` returns a non-nullable `array` and the
  special `'null'` validator name was removed.
  ([#4](https://github.com/aporat/laravel-appstore-purchases/pull/4),
  [#5](https://github.com/aporat/laravel-appstore-purchases/pull/5))
- `GoogleOidcPushVerifier` resolves `google/auth` outside its try block, so a missing package
  surfaces as a `RuntimeException` (HTTP 500) rather than being reported as a rejected push.
  ([#17](https://github.com/aporat/laravel-appstore-purchases/pull/17))
- `minimum-stability` set to `dev` in composer.json, matching the `dev-main` requirement on
  `aporat/store-receipt-validator`.
  ([#5](https://github.com/aporat/laravel-appstore-purchases/pull/5))
- Package description and keywords mention Google Play, Amazon, iTunes and in-app purchases.
  ([#5](https://github.com/aporat/laravel-appstore-purchases/pull/5), commit c376053)
- Dev tooling: `phpunit/phpunit` allows `^12.0 || ^13.0`, `orchestra/testbench` `^10.0 || ^11.0`;
  `.phpunit.cache/` ignored. GitHub Actions bumped (`actions/checkout` 7, `actions/cache` 6,
  `codecov/codecov-action` 7, `ramsey/composer-install` 4).
  ([#4](https://github.com/aporat/laravel-appstore-purchases/pull/4),
  [#8](https://github.com/aporat/laravel-appstore-purchases/pull/8), commit b813551, Dependabot)
- README: the `vendor:publish` command names the right provider class, the manual-validation
  example uses `ReceiptValidator\AppleAppStore\Validator`, and new sections document logging,
  Google Play notifications, the environment argument and the accepted driver names.
  ([#4](https://github.com/aporat/laravel-appstore-purchases/pull/4),
  [#17](https://github.com/aporat/laravel-appstore-purchases/pull/17), commits b813551 and c376053)

### Fixed
- The driver name shipped in the default config, `'validator' => 'apple-app-store'`, did not
  resolve in 1.0.0 through 2.0.0: `build()` derived the factory method with `ucwords()`, which does
  not split on hyphens, so only the camel-case spelling `appleAppStore` worked and the published
  config threw "Validator [apple-app-store] is not supported."
  ([#4](https://github.com/aporat/laravel-appstore-purchases/pull/4), then replaced by the driver
  map in [#17](https://github.com/aporat/laravel-appstore-purchases/pull/17))
- The committed `composer.lock` pinned `aporat/store-receipt-validator` at a commit that predates
  its `GooglePlay` namespace, so a clean install could not load any of the Google Play classes and
  CI was red after commit c376053. ([#17](https://github.com/aporat/laravel-appstore-purchases/pull/17))
- CI: the Codecov upload condition was nested under `with:` instead of being a step `if:`, and the
  PHP matrix had a typo (`[8.4. 8.5]`).
  ([#4](https://github.com/aporat/laravel-appstore-purchases/pull/4),
  [#6](https://github.com/aporat/laravel-appstore-purchases/pull/6))

## [2.0.0] - 2025-09-17

### Added
- The package config is merged with `mergeConfigFrom()`, so the manager works without publishing
  `config/appstore-purchases.php`. (commit b3105c0)
- Apple signing key file existence and readability are checked before reading, with distinct
  `RuntimeException` messages for each. (commit e7ac9e4)

### Changed
- **Breaking:** `aporat/store-receipt-validator` requirement moved from `^6.1` back to `dev-main`
  while the package's own `minimum-stability` stayed `stable`. Consuming applications must allow
  dev stability for that package to install 2.0.0. (commit b3105c0)
- Minimum PHP version lowered from `^8.4` to `^8.3`; `ext-openssl` is now required; dev
  `orchestra/testbench` widened to `^8.0 || ^9.0 || ^10.0`. (commit b3105c0)
- `AppStorePurchases` facade and `AppleAppStoreServerNotificationController` are `final` and declare
  `strict_types`; the controller's `switch` became a `match` that ignores unmapped notification
  types and returns `Response::HTTP_NO_CONTENT`. The facade's `@mixin` points at the manager.
  (commit b3105c0)
- Composer scripts call `vendor/bin/phpunit` and `vendor/bin/phpstan` explicitly, and the PHPStan
  level dropped from 8 to 5. (commit b3105c0)

### Fixed
- The `environment` config key is honoured. Validators were previously always constructed with
  `Environment::PRODUCTION` regardless of configuration; the value is now read as a string (via
  `Environment::fromString()`) or an `Environment` instance and passed to the Apple, iTunes and
  Amazon validators. (commit e7ac9e4)
- `vendor:publish` could not find the config file: `publishes()` pointed one directory too high
  (`__DIR__.'/../../config/...'`). (commit b3105c0)
- An undefined validator name is now reported from `getConfig()` as "App store validator [name] is
  not defined." rather than failing on a null config array. (commit e7ac9e4)

## [1.0.2] - 2025-05-05

### Added
- Amazon Appstore validator driver (`'validator' => 'amazon'` with `developer_secret`), built on
  `ReceiptValidator\Amazon\Validator`, and an `amazon` entry in the default config.
  `supportedValidators()` lists it. (commit da90993)
- Events for the remaining Apple App Store Server Notification types: `ExternalPurchaseToken`,
  `OneTimeCharge`, `PurchaseRevoked`, `SubscriptionFailedToRenew`, `SubscriptionPriceIncrease`,
  `SubscriptionRenewalChangedPref`, `SubscriptionRenewalExtended` and
  `SubscriptionRenewalExtension`. The controller now dispatches these plus `GracePeriodExpired`,
  `OfferRedeemed`, `PurchaseRefundDeclined`, `PurchaseRefundReversed` and `PurchaseRefunded`, which
  existed in 1.0.0 but were never dispatched. (commits a834013 and 972c73f)
- A `RuntimeException` when the Apple signing key file cannot be read, instead of passing `false`
  to the validator. (commit 972c73f)
- `phpstan/phpstan` dev dependency and a `composer analyze` script (level 8). (commit 972c73f)
- `environment` key on the `itunes` config entry. (commit a834013)

### Changed
- **Breaking:** `PurchaseEvent` now carries the whole `ReceiptValidator\AppleAppStore\ServerNotification`
  as `public $notification` instead of `AbstractTransaction $transaction`. Listeners must read
  `$event->notification->getTransaction()`. (commit 972c73f)
- **Breaking:** `Events\Test` extends `PurchaseEvent` and carries the notification; in 1.0.0 it was
  an empty class dispatched without arguments. (commit 972c73f)
- `aporat/store-receipt-validator` pinned to `^6.1` instead of `dev-main`. (commit 7e35e38)
- Default Apple `key_path` changed to `app_path('../resources/keys/authkey_ABC123XYZ.p8')`.
  (commit a834013)
- Note: the `environment` config value was converted to an `Environment` enum in this release but
  still not used; every validator was built with `Environment::PRODUCTION` until 2.0.0.

## [1.0.0] - 2025-05-02

### Added
- `AppStorePurchasesManager` with `get(string $name)` (cached per name), `build(array $config)` and
  `supportedValidators()`, resolving validators from `config('appstore-purchases.validators')`.
  Drivers: `apple-app-store` (`key_path`, `key_id`, `issuer_id`, `bundle_id`) wrapping
  `ReceiptValidator\AppleAppStore\Validator`, and `itunes` (`shared_secret`) wrapping
  `ReceiptValidator\iTunes\Validator`. Validators are always built for `Environment::PRODUCTION`;
  the `environment` config key is present but unused. Note: because of how the factory method name
  was derived, only the `appleAppStore` spelling resolved, not the `apple-app-store` value shipped
  in the default config (fixed in 3.0.0).
- `AppStorePurchasesServiceProvider`, a deferrable provider binding the `appstore-purchases`
  singleton and publishing `config/appstore-purchases.php` under the `config` tag, plus the
  `AppStorePurchases` facade.
- `Http\Controllers\AppleAppStoreServerNotificationController`, an invokable controller that
  decodes an App Store Server Notification V2 payload and dispatches `ConsumptionRequest`,
  `SubscriptionCreated`, `SubscriptionRenewalChanged`, `SubscriptionRenewed`, `SubscriptionExpired`
  or `Test`, answering 204. Event classes `GracePeriodExpired`, `OfferRedeemed`,
  `PurchaseRefundDeclined`, `PurchaseRefundReversed` and `PurchaseRefunded` are defined but not yet
  dispatched. All purchase events extend `PurchaseEvent`, which carries the
  `ReceiptValidator\AbstractTransaction` as `public $transaction`.
- composer: package `aporat/laravel-appstore-purchases` requiring PHP `^8.4`, `illuminate/support`
  `^10.0 || ^11.0 || ^12.0` and `aporat/store-receipt-validator` `dev-main`, with
  `laravel/pint`, `phpunit/phpunit` 12 and `orchestra/testbench` 10 for development.

[Unreleased]: https://github.com/aporat/laravel-appstore-purchases/compare/3.0.0...HEAD
[3.0.0]: https://github.com/aporat/laravel-appstore-purchases/compare/2.0.0...3.0.0
[2.0.0]: https://github.com/aporat/laravel-appstore-purchases/compare/1.0.2...2.0.0
[1.0.2]: https://github.com/aporat/laravel-appstore-purchases/compare/1.0.0...1.0.2
[1.0.0]: https://github.com/aporat/laravel-appstore-purchases/releases/tag/1.0.0
