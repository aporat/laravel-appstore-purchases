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
    ],

    'validators' => [
        'apple' => [
            'validator' => 'apple-app-store',
            'key_path' => env('APPLE_APPSTORE_KEY_PATH', base_path('resources/keys/authkey.p8')),
            'key_id' => env('APPLE_APPSTORE_KEY_ID', ''),
            'issuer_id' => env('APPLE_APPSTORE_ISSUER_ID', ''),
            'bundle_id' => env('APPLE_APPSTORE_BUNDLE_ID', ''),
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
