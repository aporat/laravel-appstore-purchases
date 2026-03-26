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
    ],
];
