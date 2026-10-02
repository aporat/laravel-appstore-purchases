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

    'validators' => [
        'apple' => [
            'validator' => 'apple-app-store',
            'key_path' => env('APPLE_APPSTORE_KEY_PATH', base_path('resources/keys/authkey.p8')),
            'key_id' => env('APPLE_APPSTORE_KEY_ID', ''),
            'issuer_id' => env('APPLE_APPSTORE_ISSUER_ID', ''),
            'bundle_id' => env('APPLE_APPSTORE_BUNDLE_ID', ''),
            // The app's numeric Apple ID from App Store Connect. Optional, but
            // recommended in production: signed app transactions and server
            // notifications must then carry it. Apple omits it from sandbox
            // payloads, so it is not checked there.
            'app_apple_id' => env('APPLE_APPSTORE_APP_APPLE_ID'),
            'environment' => env('APPLE_APPSTORE_ENVIRONMENT', 'SANDBOX'),
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
    | Verification requires the google/auth package (composer require google/auth).
    |
    */

    'google_play' => [
        'rtdn' => [
            'audience' => env('GOOGLE_PLAY_RTDN_AUDIENCE'),
            'service_account_email' => env('GOOGLE_PLAY_RTDN_SERVICE_ACCOUNT_EMAIL'),
        ],
    ],
];
