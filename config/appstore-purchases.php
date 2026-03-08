<?php

use ReceiptValidator\Environment;

return [
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
