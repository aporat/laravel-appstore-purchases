<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Set a log channel name to enable PSR-3 request/response logging on all
    | validators (e.g. 'stack', 'daily', 'stderr'). Set to null to disable
    | logging entirely (the default). Individual validators can override this
    | with their own 'log_channel' key.
    |
    */

    'logging' => [
        'channel' => env('APPSTORE_LOG_CHANNEL'),
        // Where the server-notification endpoints (App Store, Google Play
        // RTDN) and the Pub/Sub push verifier write: decode failures,
        // listener exceptions, rejected pushes and each verified push. Null
        // uses the application's default log channel.
        'notifications_channel' => env('APPSTORE_NOTIFICATIONS_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Replay Protection
    |--------------------------------------------------------------------------
    |
    | Both notification endpoints remember the IDs they have handled (Apple's
    | notificationUUID, Pub/Sub's messageId) in a cache store for 'ttl'
    | seconds and acknowledge a repeated delivery without dispatching its
    | event again. This deduplicates store retries and stops a captured
    | notification from being replayed. Use a shared store (redis, database)
    | behind a load balancer; null uses the application's default store.
    |
    */

    'replay_protection' => [
        'enabled' => (bool) env('APPSTORE_REPLAY_PROTECTION', true),
        'store' => env('APPSTORE_REPLAY_CACHE_STORE'),
        'ttl' => (int) env('APPSTORE_REPLAY_TTL', 604800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Token and Certificate Cache
    |--------------------------------------------------------------------------
    |
    | Google Play validators share their Android Publisher access token through
    | this cache store (one entry per service account), refreshing it
    | 'token_refresh_margin' seconds before Google's expiry, instead of
    | minting one per PHP process. The RTDN push verifier caches Google's
    | signing certificates here for as long as Google's Cache-Control max-age
    | allows. Use a shared store (redis, database) so every server and worker
    | reuses them; null uses the application's default store. Disable to fall
    | back to a token per process and a certificate fetch per push.
    |
    */

    'google_cache' => [
        'enabled' => (bool) env('APPSTORE_GOOGLE_CACHE', true),
        'store' => env('APPSTORE_GOOGLE_CACHE_STORE'),
        'token_refresh_margin' => (int) env('APPSTORE_GOOGLE_TOKEN_REFRESH_MARGIN', 300),
    ],

    'validators' => [
        'apple' => [
            'validator' => 'apple-app-store',
            // The In-App Purchase key: the .p8 file's path, or its PEM contents
            // inline (e.g. from a secrets manager). A value with a PEM header is
            // the key itself, anything else is read as a path. Line breaks that
            // an env channel flattened to spaces or "\n" are restored before use.
            'private_key' => env('APPLE_APPSTORE_PRIVATE_KEY', base_path('resources/keys/authkey.p8')),
            'key_id' => env('APPLE_APPSTORE_KEY_ID', ''),
            'issuer_id' => env('APPLE_APPSTORE_ISSUER_ID', ''),
            'bundle_id' => env('APPLE_APPSTORE_BUNDLE_ID', ''),
            // The app's numeric Apple ID from App Store Connect. Optional, but
            // recommended in production: signed app transactions and server
            // notifications must then carry it. Apple omits it from sandbox
            // payloads, so it is not checked there.
            'app_apple_id' => env('APPLE_APPSTORE_APP_APPLE_ID'),
            'environment' => env('APPLE_APPSTORE_ENVIRONMENT', 'SANDBOX'),
            // The auto-renewable subscription products this app sells, for the
            // subscription layer (see 'subscriptions' below). A transaction for
            // any other product is ignored there. Google Play entries take the
            // same key.
            // 'subscription_products' => ['com.myapp.plan.monthly', 'com.myapp.plan.yearly'],
        ],
        'itunes' => [
            'validator' => 'itunes',
            'shared_secret' => env('ITUNES_SHARED_SECRET', ''),
            'environment' => env('ITUNES_ENVIRONMENT', 'SANDBOX'),
        ],
        'amazon' => [
            'validator' => 'amazon',
            'developer_secret' => env('AMAZON_DEVELOPER_SECRET', ''),
            'environment' => env('AMAZON_ENVIRONMENT', 'SANDBOX'),
        ],
        'google-play' => [
            'validator' => 'google-play',
            'package_name' => env('GOOGLE_PLAY_PACKAGE_NAME', ''),
            // A Google Cloud service account with access to the app in the Play
            // Console. Provide the JSON key file path (or 'service_account_json'
            // with the raw contents instead).
            'service_account_key_path' => env('GOOGLE_PLAY_SERVICE_ACCOUNT_KEY_PATH', base_path('resources/keys/google-play-service-account.json')),
            // Google has no sandbox endpoint; licence-tester purchases are flagged
            // on the response itself. This value is informational only.
            'environment' => env('GOOGLE_PLAY_ENVIRONMENT', 'PRODUCTION'),
            // Optional: this app's own Real-time Developer Notification push
            // expectations, checked instead of the global 'google_play.rtdn'
            // block below for notifications carrying this package name. Use
            // it when several Play apps push to one API from different Cloud
            // projects. Same keys and semantics as the global block.
            // 'rtdn' => [
            //     'audience' => env('GOOGLE_PLAY_RTDN_AUDIENCE'),
            //     'service_account_email' => env('GOOGLE_PLAY_RTDN_SERVICE_ACCOUNT_EMAIL'),
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Play Real-time Developer Notifications
    |--------------------------------------------------------------------------
    |
    | Play delivers notifications through a Cloud Pub/Sub push subscription.
    | When the subscription is configured to authenticate with an OIDC token,
    | set 'audience' to the push endpoint URL (the value Pub/Sub puts in the
    | token's "aud" claim) and, optionally, the service account email the
    | subscription signs with. Leave 'audience' null to skip verification.
    |
    | Both accept a list as well. These are the defaults; a Google Play
    | validator entry above can carry its own 'rtdn' block, which is used
    | instead for notifications with that entry's package name.
    |
    | With no audience configured every push is accepted and a warning is
    | logged for each one. Always set an audience in production.
    |
    */

    'google_play' => [
        'rtdn' => [
            'audience' => env('GOOGLE_PLAY_RTDN_AUDIENCE'),
            'service_account_email' => env('GOOGLE_PLAY_RTDN_SERVICE_ACCOUNT_EMAIL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscriptions on accounts
    |--------------------------------------------------------------------------
    |
    | The entitlement layer: an Eloquent account model that uses
    | Subscriptions\ManagesStoreSubscriptions, a `subscriptions` table of
    | Subscriptions\StoreSubscription rows next to it, the SubscriptionRecorder
    | behind a `POST .../subscription` endpoint, the listeners that apply the
    | store notifications above to those accounts, and the re-verify command.
    | See the "Subscriptions on accounts" section of the README.
    |
    | 'subscribers' names the Account + Subscription model pairs the listeners
    | search, each with the Google Play validator entry whose package its
    | Android app has. Leave it empty and the listeners are not registered.
    |
    */

    'subscribers' => [
        // ['account' => App\Models\Account::class, 'subscription' => App\Models\Subscription::class, 'play_validator' => 'google-play'],
    ],

    'subscriptions' => [
        // Where the layer logs: routine facts at info, refused client input at
        // warning, failed store calls at error. Null for the default channel.
        'log_channel' => env('APPSTORE_SUBSCRIPTIONS_LOG_CHANNEL'),

        // How long a verified client credential stays claimed, in seconds, so
        // a StoreKit restore burst pays for one store round-trip. 0 disables.
        'dedupe_ttl' => (int) env('APPSTORE_SUBSCRIPTIONS_DEDUPE_TTL', 60),

        // The request fields a subscription post carries.
        'request' => [
            'store' => 'store',
            'purchase_token' => 'purchase_token',
            'signed_transaction' => 'signed_transaction',
            'app_receipt' => 'app_receipt',
        ],

        // Defaults for Subscriptions\Console\VerifyStoreSubscriptions. A
        // command may name its own block; keys it leaves out fall back here.
        'verification' => [
            // Only look at subscriptions expiring within this many days.
            'check_within_days' => 3,
            // Leave this long between store round-trips for the same subscription.
            'recheck_after_hours' => 12,
            // A row whose store call failed is eligible again this long after
            // the failure instead of waiting out recheck_after_hours.
            'retry_failed_after_hours' => 1,
            // Give up on subscriptions that lapsed more than this many days ago;
            // a returning user re-posts their credential from the app.
            'abandon_after_days' => 30,
            // Ceiling on how many subscriptions one run will verify.
            'batch_limit' => 200,
            // Pause between store calls, so a batch does not burst against
            // Apple's or Google's rate limits.
            'min_sleep_microseconds' => 100000,
            'max_sleep_microseconds' => 500000,
        ],
    ],
];
